<?php

namespace Database\Seeders\Support;

use App\Editorial\EditorialWorkflow;
use App\Enums\Locale;
use App\Enums\Role;
use App\Filament\Support\Taxonomy\TagWriter;
use App\Models\Article;
use App\Models\ArticleTranslation;
use App\Models\CategoryTranslation;
use App\Models\Tag;
use App\Models\User;
use App\Support\Media\HeroImages;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Imports a validated LaunchContentPackage. Unlike DemoContentSeeder it is meant for production.
 *
 * Every story is one database transaction: picture copied to the public disk, article, tags,
 * both language versions, publication. A failure rolls that story back (and removes its files),
 * and the other stories carry on. Publication goes through EditorialWorkflow::publishNow() as the
 * given admin, so the audit trail shows who published and the policies apply as for any editor.
 *
 * Idempotent: a story whose article already points at articles/launch/<key>.* (soft-deleted
 * ones included, so a story an editor removed is not resurrected) is skipped.
 *
 * The $report callback receives (level, message); level is info, warn, error or line.
 */
final class LaunchContentImporter
{
    public const IMAGE_DIRECTORY = 'articles/launch';

    /** The staff account every imported article is bylined to. It can never sign in. */
    public const BYLINE_EMAIL = 'desk@sisi.news';

    private const DISK = 'public';

    public const LOCK = 'sisi-launch-content-import';

    private const LOCK_SECONDS = 900;

    public function __construct(
        private readonly TagWriter $tags,
        private readonly EditorialWorkflow $workflow,
    ) {}

    /** Why $user cannot publish the launch content, or null if they can. */
    public static function publisherProblem(?User $user, string $email): ?string
    {
        if ($user === null) {
            return "There is no account for {$email}. Create the admin first with `php artisan sisi:create-admin`.";
        }
        if ($user->role !== Role::Admin) {
            return "{$email} is a {$user->role->value}, not an admin. Only an admin publishes the launch content.";
        }
        if (! $user->is_active) {
            return "The admin account {$email} is deactivated, and a deactivated account cannot publish.";
        }

        return null;
    }

    /** Says what import() would do and writes nothing, not even the byline account. */
    public function dryRun(LaunchContentPackage $package, ?Closure $report = null): LaunchImportResult
    {
        $this->assertImportable($package);
        $report ??= static fn () => null;

        $result = new LaunchImportResult;
        $done = $this->importedKeys();

        $bylineExists = User::query()->where('email', self::BYLINE_EMAIL)->exists();
        $report('line', 'Byline: '.($bylineExists ? 'the existing account '.self::BYLINE_EMAIL.' is reused.' : 'would create the inactive staff account '.self::BYLINE_EMAIL.'.'));

        foreach ($package->stories() as $story) {
            if (isset($done[$story->key])) {
                $result->skipped[] = $story->key;
                $report('line', "Would skip {$story->key}: already imported.");

                continue;
            }

            $result->imported[] = $story->key;
            $report('line', sprintf(
                'Would import %s: %s%s, %d %s, published %d h before the run, picture %s -> %s.',
                $story->key, $story->category, $story->featured ? ', featured' : '', count($story->tags), Str::plural('tag', count($story->tags)), $story->hoursAgo, $story->image, $story->imagePath(),
            ));
        }

        $result->createdByline = ! $bylineExists && $result->imported !== [];

        return $result;
    }

    public function import(LaunchContentPackage $package, User $publisher, ?Closure $report = null): LaunchImportResult
    {
        $this->assertImportable($package);
        $problem = self::publisherProblem($publisher, $publisher->email);
        if ($problem !== null) {
            throw new LaunchImportException($problem);
        }

        // Two runs at once would both see a story as new and import it twice.
        $lock = Cache::lock(self::LOCK, self::LOCK_SECONDS);
        if (! $lock->get()) {
            throw new LaunchImportException('Another launch-content import is running. Wait for it to finish (the lock expires on its own after '.(self::LOCK_SECONDS / 60).' minutes if it was interrupted).');
        }

        try {
            return $this->run($package, $publisher, $report ?? static fn () => null);
        } finally {
            $lock->release();
        }
    }

    private function assertImportable(LaunchContentPackage $package): void
    {
        if (! $package->isValid()) {
            throw new LaunchImportException("The content has problems, nothing was imported:\n - ".implode("\n - ", $package->problems()));
        }
    }

    private function run(LaunchContentPackage $package, User $publisher, Closure $report): LaunchImportResult
    {
        $result = new LaunchImportResult;
        $categories = CategoryTranslation::query()->where('locale', Locale::En->value)->pluck('category_id', 'slug');
        $done = $this->importedKeys();
        // One clock reading for the whole run keeps the staggering between stories exact.
        $startedAt = now();
        $tagsBefore = Tag::query()->count();
        $byline = null;

        foreach ($package->stories() as $story) {
            if (isset($done[$story->key])) {
                $result->skipped[] = $story->key;
                $report('line', "Skipping {$story->key}: already imported.");

                continue;
            }

            // Outside the try: with no byline no story can succeed, so that failure ends the run.
            $byline ??= $this->byline($package, $result, $report);

            try {
                $categoryId = $categories[$story->category] ?? throw new RuntimeException("The section \"{$story->category}\" no longer exists.");

                DB::transaction(fn () => $this->importStory($story, $byline, $publisher, $categoryId, $startedAt));
            } catch (Throwable $e) {
                // Files are not part of the transaction. Nothing references them now, so none may stay.
                HeroImages::delete($story->imagePath());
                Log::error('Launch content story failed to import', ['key' => $story->key, 'exception' => $e]);

                $result->failed[$story->key] = $e->getMessage();
                $report('error', "Failed {$story->key}, rolled back: {$e->getMessage()}");

                continue;
            }

            $result->imported[] = $story->key;
            $report('line', "Imported {$story->key} ({$story->category}, published {$story->hoursAgo} h ago).");
            if (! Storage::disk(self::DISK)->exists(HeroImages::variantPath($story->imagePath(), 'card'))) {
                $report('warn', "{$story->key}: the resized WebP versions of the picture could not be made, so the original is served as it is. See the application log.");
            }
        }

        $result->createdTags = Tag::query()->count() - $tagsBefore;

        return $result;
    }

    /** @return array<string, true> keys of the stories already in the database */
    private function importedKeys(): array
    {
        return Article::withTrashed()
            ->where('hero_image', 'like', self::IMAGE_DIRECTORY.'/%')
            ->pluck('hero_image')
            ->mapWithKeys(fn (string $path) => [pathinfo($path, PATHINFO_FILENAME) => true])
            ->all();
    }

    /**
     * The byline account, created on first need. It is inactive with a random password nobody
     * is told, so it cannot sign in and the panel refuses it. An existing account is reused untouched.
     */
    private function byline(LaunchContentPackage $package, LaunchImportResult $result, Closure $report): User
    {
        $existing = User::query()->where('email', self::BYLINE_EMAIL)->first();
        if ($existing !== null) {
            if ($existing->is_active) {
                $report('warn', self::BYLINE_EMAIL.' is an active account. It is the byline of the launch stories and nobody should sign in as it. The Team area hides this address, so it has to be switched off from a shell: php artisan tinker, then App\Models\User::where("email", "'.self::BYLINE_EMAIL.'")->update(["is_active" => false]).');
            }

            return $existing;
        }

        $byline = User::query()->create([
            'name' => $package->deskName(),
            'name_ar' => $package->deskNameAr(),
            'email' => self::BYLINE_EMAIL,
            'password' => Str::random(64),
            'role' => Role::Journalist,
            'is_active' => false,
        ]);

        $result->createdByline = true;
        $report('line', 'Created the inactive staff account '.self::BYLINE_EMAIL.' as the byline.');

        return $byline;
    }

    private function importStory(LaunchStory $story, User $byline, User $publisher, int $categoryId, CarbonInterface $startedAt): void
    {
        $path = $story->imagePath();
        $this->storeImage($story, $path);
        HeroImages::process($path);

        $article = new Article([
            'category_id' => $categoryId,
            'author_id' => $byline->getKey(),
            'hero_image' => $path,
            'is_featured' => $story->featured,
        ]);
        $article->save();

        $article->tags()->sync(array_values(array_unique(array_map(
            fn (array $tag) => $this->tags->findOrCreate($tag['en'], $tag['ar'])->getKey(),
            $story->tags,
        ))));

        $publishedAt = $startedAt->copy()->subHours($story->hoursAgo);
        foreach ([Locale::Ar, Locale::En] as $locale) {
            $text = $story->text[$locale->value];

            $translation = new ArticleTranslation([
                'article_id' => $article->getKey(),
                'locale' => $locale,
                'title' => $text['title'],
                'slug' => $text['slug'],
                'excerpt' => $text['excerpt'],
                // The model's body mutator runs the HTML sanitizer, as for any staff-written story.
                'body' => $text['body'],
                'hero_caption' => $text['hero_caption'],
                'meta_description' => $text['meta_description'],
            ]);
            // The date is set first and approve() keeps a date that is already there; the status is not
            // fillable and only the workflow moves it.
            $translation->forceFill(['published_at' => $publishedAt])->save();

            // Reload so the database default status ('draft') is on the model, and hand the workflow
            // the article it would otherwise lazy-load for the policy check.
            $translation->refresh()->setRelation('article', $article);
            $this->workflow->publishNow($translation, $publisher);
        }
    }

    private function storeImage(LaunchStory $story, string $path): void
    {
        $stream = @fopen($story->sourceFile, 'rb');
        if ($stream === false) {
            throw new RuntimeException("The picture {$story->image} cannot be read.");
        }

        try {
            $written = Storage::disk(self::DISK)->put($path, $stream);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        if (! $written) {
            throw new RuntimeException("The picture could not be written to the public disk as {$path}.");
        }
    }
}
