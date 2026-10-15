<?php

namespace Database\Seeders\Support;

use App\Enums\Locale;
use App\Models\CategoryTranslation;
use App\Support\HtmlSanitizer;
use App\Support\Media\HeroImages;
use App\Support\Slugger;
use Illuminate\Support\Str;
use JsonException;

/**
 * Reads a launch content directory (stories.json + images/) and checks all of it against
 * the contract before anything is imported, collecting EVERY problem rather than stopping at
 * the first, so an editor can fix the file in one pass. Reads the database (categories) but
 * writes nothing. A package with problems must not be imported.
 *
 * Contract: stories.json = {desk: {name, name_ar}, stories: [{key, category (English slug),
 * image, featured?, published_hours_ago, tags?: [{en, ar}], en: {...}, ar: {...}}]}. Each
 * language block needs title, excerpt, body (HTML) and hero_caption (the picture's credit
 * line, which a CC BY licence obliges us to show); slug and meta_description are optional.
 * images/credits.json (optional) documents the licence of every picture.
 */
final class LaunchContentPackage
{
    public const STORIES_FILE = 'stories.json';

    public const CREDITS_FILE = 'images/credits.json';

    private const IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp'];

    /** Heavier pictures still import; they only slow the home page down, so they warn. */
    private const RECOMMENDED_MAX_WIDTH = 1600;

    private const RECOMMENDED_MAX_BYTES = 460_800;

    private const MAX_HOURS_AGO = 8760;

    private const STORY_FIELDS = ['key', 'category', 'image', 'featured', 'published_hours_ago', 'tags', 'en', 'ar'];

    private const TEXT_FIELDS = ['title', 'slug', 'excerpt', 'body', 'hero_caption', 'meta_description'];

    /** Anything that looks like markup or an entity: these fields are escaped on output, so it would show literally. */
    private const MARKUP = '/<[a-z\/!]|&(?:[a-z]+|#\d+|#x[0-9a-f]+);/i';

    private string $root = '';

    private string $deskName = '';

    private string $deskNameAr = '';

    /** @var list<string> */
    private array $problems = [];

    /** @var list<string> */
    private array $warnings = [];

    /** @var list<LaunchStory> */
    private array $stories = [];

    private function __construct(public readonly string $directory) {}

    public static function load(string $directory): self
    {
        $package = new self(rtrim($directory, '/\\'));
        $package->read();

        return $package;
    }

    public function isValid(): bool
    {
        return $this->problems === [];
    }

    /** @return list<string> everything that blocks the import, each naming the story it is about */
    public function problems(): array
    {
        return $this->problems;
    }

    /** @return list<string> things worth a look that do not block the import */
    public function warnings(): array
    {
        return $this->warnings;
    }

    /** @return list<LaunchStory> empty unless the package is valid */
    public function stories(): array
    {
        return $this->isValid() ? $this->stories : [];
    }

    public function deskName(): string
    {
        return $this->deskName;
    }

    public function deskNameAr(): string
    {
        return $this->deskNameAr;
    }

    private function read(): void
    {
        $root = realpath($this->directory);
        if ($root === false || ! is_dir($root)) {
            $this->problems[] = "The content directory {$this->directory} does not exist.";

            return;
        }
        $this->root = $root;

        $data = $this->readJson(self::STORIES_FILE, required: true);
        $credits = $this->readJson(self::CREDITS_FILE, required: false);
        if ($data === null) {
            return;
        }

        $this->readDesk($data['desk'] ?? null);

        $stories = $data['stories'] ?? null;
        if (! is_array($stories) || ! array_is_list($stories) || $stories === []) {
            $this->problems[] = 'stories must be a non-empty list of stories.';

            return;
        }

        $categories = CategoryTranslation::query()->where('locale', Locale::En->value)->pluck('slug')->sort()->values()->all();
        $seen = [];
        foreach ($stories as $index => $raw) {
            $this->readStory($raw, $index + 1, $categories, $seen, $credits);
        }
    }

    /** @return array<string, mixed>|null null once a problem has been recorded */
    private function readJson(string $relative, bool $required): ?array
    {
        $file = $this->root.'/'.$relative;
        if (! is_file($file)) {
            $message = "{$relative} is missing in {$this->directory}.";
            $required ? $this->problems[] = $message : $this->warnings[] = $message.' The picture credits are then only the hero_caption lines.';

            return null;
        }

        $raw = file_get_contents($file);
        if ($raw === false) {
            $this->problems[] = "{$relative} cannot be read.";

            return null;
        }

        try {
            // A byte-order mark (Windows editors add one) would make an otherwise valid file unparseable.
            $decoded = json_decode((string) preg_replace('/^\xEF\xBB\xBF/', '', $raw), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            $this->report($required, "{$relative} is not valid JSON: {$e->getMessage()}.");

            return null;
        }

        if (! is_array($decoded) || array_is_list($decoded)) {
            $this->report($required, "{$relative} must be a JSON object.");

            return null;
        }

        return $decoded;
    }

    private function report(bool $blocking, string $message): void
    {
        $blocking ? $this->problems[] = $message : $this->warnings[] = $message;
    }

    private function readDesk(mixed $desk): void
    {
        if (! is_array($desk) || array_is_list($desk)) {
            $this->problems[] = 'desk must be an object with name and name_ar.';

            return;
        }

        $this->deskName = $this->plainText($desk, 'name', 'desk', true, 255) ?? '';
        $this->deskNameAr = $this->plainText($desk, 'name_ar', 'desk', true, 255) ?? '';
    }

    /**
     * @param  list<string>  $categories  English slugs that exist in the database
     * @param  array<string, int>  $seen  story key => story number, to catch duplicates
     * @param  array<string, mixed>|null  $credits
     */
    private function readStory(mixed $raw, int $number, array $categories, array &$seen, ?array $credits): void
    {
        $label = "story #{$number}";
        if (! is_array($raw) || array_is_list($raw)) {
            $this->problems[] = "{$label} must be an object.";

            return;
        }

        $before = count($this->problems);

        $key = $this->readKey($raw, $label, $number, $seen);
        if ($key !== null) {
            $label .= " ({$key})";
        }

        foreach (array_diff(array_keys($raw), self::STORY_FIELDS) as $unknown) {
            $this->warnings[] = "{$label}: unknown field \"{$unknown}\" is ignored.";
        }

        $category = $this->readCategory($raw, $label, $categories);
        $image = $this->readImage($raw, $label, $credits);
        $featured = $this->readFeatured($raw, $label);
        $hoursAgo = $this->readHoursAgo($raw, $label);
        $tags = $this->readTags($raw, $label);
        $text = [];
        foreach (Locale::cases() as $locale) {
            $text[$locale->value] = $this->readText($raw[$locale->value] ?? null, $locale, $label);
        }

        if (count($this->problems) > $before || $key === null || $category === null || $image === null || $hoursAgo === null) {
            return;
        }

        $this->stories[] = new LaunchStory($key, $category, $image['image'], $image['file'], $image['extension'], $featured, $hoursAgo, $tags, $text);
    }

    /** @param  array<string, int>  $seen */
    private function readKey(array $raw, string $label, int $number, array &$seen): ?string
    {
        $key = $raw['key'] ?? null;
        if (! is_string($key) || preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $key) !== 1 || strlen($key) > 100) {
            $this->problems[] = "{$label}: key must be unique lower-case kebab text such as \"nile-corniche-evening\" (at most 100 characters).";

            return null;
        }

        if (isset($seen[$key])) {
            $this->problems[] = "{$label} ({$key}): duplicate key, already used by story #{$seen[$key]}.";

            return null;
        }
        $seen[$key] = $number;

        return $key;
    }

    /** @param  list<string>  $categories */
    private function readCategory(array $raw, string $label, array $categories): ?string
    {
        $category = $raw['category'] ?? null;
        if (is_string($category) && in_array($category, $categories, true)) {
            return $category;
        }

        $known = $categories === [] ? 'none exist yet, run "php artisan db:seed" first' : 'known: '.implode(', ', $categories);
        $this->problems[] = "{$label}: category ".(is_string($category) ? "\"{$category}\"" : 'is missing and')." must be the English slug of an existing section ({$known}).";

        return null;
    }

    /**
     * @param  array<string, mixed>|null  $credits
     * @return array{image: string, file: string, extension: string}|null
     */
    private function readImage(array $raw, string $label, ?array $credits): ?array
    {
        $image = $raw['image'] ?? null;
        if (! is_string($image) || trim($image) === '') {
            $this->problems[] = "{$label}: image is required (a path such as images/photo.jpg).";

            return null;
        }

        $extension = strtolower(pathinfo($image, PATHINFO_EXTENSION));
        $segments = explode('/', $image);
        if (preg_match('#^[A-Za-z0-9][A-Za-z0-9._/-]*$#', $image) !== 1 || array_intersect($segments, ['', '.', '..']) !== []) {
            $this->problems[] = "{$label}: image path \"{$image}\" must be a plain relative path inside the content directory.";

            return null;
        }
        if (! in_array($extension, self::IMAGE_EXTENSIONS, true)) {
            $this->problems[] = "{$label}: image \"{$image}\" must be a .jpg, .jpeg, .png or .webp file.";

            return null;
        }

        $file = realpath($this->root.'/'.$image);
        if ($file === false || ! is_file($file)) {
            $this->problems[] = "{$label}: image file \"{$image}\" does not exist.";

            return null;
        }
        // A symlink inside the directory must not smuggle in a file from elsewhere on the server.
        if (! str_starts_with($file, $this->root.DIRECTORY_SEPARATOR)) {
            $this->problems[] = "{$label}: image \"{$image}\" resolves outside the content directory.";

            return null;
        }

        if (! $this->checkPicture($file, $image, $label)) {
            return null;
        }

        if ($credits !== null && ! isset($credits[$image])) {
            $this->warnings[] = "{$label}: no entry for \"{$image}\" in ".self::CREDITS_FILE.'.';
        }

        return ['image' => $image, 'file' => $file, 'extension' => $extension];
    }

    private function checkPicture(string $file, string $image, string $label): bool
    {
        $info = @getimagesize($file);
        if ($info === false || ! in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)) {
            $this->problems[] = "{$label}: \"{$image}\" is not a readable JPEG, PNG or WebP picture.";

            return false;
        }

        [$width, $height] = $info;
        if (max($width, $height) > HeroImages::MAX_SOURCE_SIDE || $width * $height > HeroImages::MAX_SOURCE_PIXELS) {
            $this->problems[] = "{$label}: \"{$image}\" is {$width}x{$height} px, larger than the server can resize.";

            return false;
        }

        $bytes = (int) filesize($file);
        if ($width > self::RECOMMENDED_MAX_WIDTH || $bytes > self::RECOMMENDED_MAX_BYTES) {
            $this->warnings[] = sprintf(
                '%s: "%s" is %d px wide and %d KB; aim for at most %d px and %d KB.',
                $label, $image, $width, (int) ceil($bytes / 1024), self::RECOMMENDED_MAX_WIDTH, self::RECOMMENDED_MAX_BYTES / 1024,
            );
        }

        return true;
    }

    private function readFeatured(array $raw, string $label): bool
    {
        $featured = $raw['featured'] ?? false;
        if (! is_bool($featured)) {
            $this->problems[] = "{$label}: featured must be true or false.";

            return false;
        }

        return $featured;
    }

    private function readHoursAgo(array $raw, string $label): ?int
    {
        $hours = $raw['published_hours_ago'] ?? null;
        if (! is_int($hours) || $hours < 0 || $hours > self::MAX_HOURS_AGO) {
            $this->problems[] = "{$label}: published_hours_ago must be a whole number of hours between 0 and ".self::MAX_HOURS_AGO.'.';

            return null;
        }

        return $hours;
    }

    /** @return list<array{en: string, ar: string}> */
    private function readTags(array $raw, string $label): array
    {
        $tags = $raw['tags'] ?? [];
        if (! is_array($tags) || ! array_is_list($tags)) {
            $this->problems[] = "{$label}: tags must be a list of {\"en\": ..., \"ar\": ...} objects.";

            return [];
        }

        $valid = [];
        foreach ($tags as $position => $tag) {
            $tagLabel = "{$label}: tag #".($position + 1);
            if (! is_array($tag) || array_is_list($tag)) {
                $this->problems[] = "{$tagLabel} must be an object with en and ar.";

                continue;
            }

            $names = [];
            foreach (Locale::values() as $language) {
                $name = $this->plainText($tag, $language, $tagLabel, true, 100);
                if ($name !== null && Slugger::make($name) === '') {
                    $this->problems[] = "{$tagLabel}: {$language} name \"{$name}\" has no letters or digits.";
                    $name = null;
                }
                $names[$language] = $name;
            }

            if ($names['en'] !== null && $names['ar'] !== null) {
                $valid[] = ['en' => $names['en'], 'ar' => $names['ar']];
            }
        }

        return $valid;
    }

    /**
     * @return array{title: string, slug: string, excerpt: string, body: string, hero_caption: string, meta_description: string}
     */
    private function readText(mixed $block, Locale $locale, string $label): array
    {
        $label .= ", {$locale->value}";
        $empty = ['title' => '', 'slug' => '', 'excerpt' => '', 'body' => '', 'hero_caption' => '', 'meta_description' => ''];

        if (! is_array($block) || array_is_list($block)) {
            $this->problems[] = "{$label}: the {$locale->value} text block is missing; every story needs both languages.";

            return $empty;
        }

        foreach (array_diff(array_keys($block), self::TEXT_FIELDS) as $unknown) {
            $this->warnings[] = "{$label}: unknown field \"{$unknown}\" is ignored.";
        }

        $title = $this->plainText($block, 'title', $label, true, 255) ?? '';
        $excerpt = $this->plainText($block, 'excerpt', $label, true, 1000) ?? '';
        $caption = $this->plainText($block, 'hero_caption', $label, true, 255) ?? '';
        $meta = $this->plainText($block, 'meta_description', $label, false, 300) ?? Str::limit($excerpt, 160);
        $slug = $this->readSlug($block, $title, $label);
        $body = $this->readBody($block, $label);

        // Catches an English text pasted into the Arabic block (or the reverse) before it goes live.
        $script = $locale === Locale::Ar ? '/\p{Arabic}/u' : '/[A-Za-z]/';
        $scriptName = $locale === Locale::Ar ? 'Arabic' : 'Latin';
        foreach (['title' => $title, 'excerpt' => $excerpt, 'body' => strip_tags($body)] as $field => $value) {
            if ($value !== '' && preg_match($script, $value) !== 1) {
                $this->problems[] = "{$label}: {$field} has no {$scriptName} letters; is it in the right language block?";
            }
        }

        return ['title' => $title, 'slug' => $slug, 'excerpt' => $excerpt, 'body' => $body, 'hero_caption' => $caption, 'meta_description' => $meta];
    }

    private function readSlug(array $block, string $title, string $label): string
    {
        $given = $block['slug'] ?? null;
        if ($given === null || $given === '') {
            return Slugger::make($title);
        }
        if (! is_string($given) || Slugger::make($given) === '') {
            $this->problems[] = "{$label}: slug must contain letters or digits (or be left out).";

            return '';
        }

        return Slugger::make($given);
    }

    private function readBody(array $block, string $label): string
    {
        $body = $block['body'] ?? null;
        if (! is_string($body) || trim($body) === '') {
            $this->problems[] = "{$label}: body is required.";

            return '';
        }

        // The same test EditorialWorkflow applies when submitting, on what will actually be stored.
        if (trim(strip_tags(HtmlSanitizer::clean($body))) === '') {
            $this->problems[] = "{$label}: body has no text left after sanitizing.";

            return '';
        }

        return trim($body);
    }

    /** @param  array<string, mixed>  $data */
    private function plainText(array $data, string $field, string $label, bool $required, int $max): ?string
    {
        $value = $data[$field] ?? null;
        if (is_string($value)) {
            $value = trim($value);
        }

        if ($value === null || $value === '') {
            if ($required) {
                $this->problems[] = "{$label}: {$field} is required and must not be empty.";
            }

            return null;
        }
        if (! is_string($value)) {
            $this->problems[] = "{$label}: {$field} must be text.";

            return null;
        }
        if (mb_strlen($value) > $max) {
            $this->problems[] = "{$label}: {$field} is ".mb_strlen($value)." characters long; the limit is {$max}.";

            return null;
        }
        if (preg_match(self::MARKUP, $value) === 1) {
            $this->problems[] = "{$label}: {$field} must be plain text, without HTML tags or entities.";

            return null;
        }

        return $value;
    }
}
