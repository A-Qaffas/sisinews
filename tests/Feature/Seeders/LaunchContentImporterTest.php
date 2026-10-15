<?php

namespace Tests\Feature\Seeders;

use App\Editorial\Transition;
use App\Enums\Locale;
use App\Enums\Role;
use App\Enums\TranslationStatus;
use App\Filament\Pages\Auth\Login;
use App\Filament\Support\Taxonomy\TagWriter;
use App\Models\Article;
use App\Models\ArticleStatusChange;
use App\Models\ArticleTranslation;
use App\Models\Tag;
use App\Models\TagTranslation;
use App\Models\User;
use App\Support\Media\HeroImages;
use Carbon\Carbon;
use Database\Seeders\Support\LaunchContentImporter;
use Database\Seeders\Support\LaunchContentPackage;
use Database\Seeders\Support\LaunchImportException;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;

class LaunchContentImporterTest extends LaunchContentTest
{
    private const PICTURES = [
        'nile-corniche-evening' => 'articles/launch/nile-corniche-evening.jpg',
        'gulf-trade-talks' => 'articles/launch/gulf-trade-talks.webp',
        'museum-after-dark' => 'articles/launch/museum-after-dark.jpg',
    ];

    /** @return array<string, int> */
    private function rowCounts(): array
    {
        $counts = [];
        foreach (['users', 'tags', 'tag_translations', 'articles', 'article_translations', 'article_tag', 'article_status_changes'] as $table) {
            $counts[$table] = DB::table($table)->count();
        }
        $counts['files'] = count(Storage::disk('public')->allFiles());

        return $counts;
    }

    private function translation(string $key, Locale $locale): ArticleTranslation
    {
        return ArticleTranslation::query()
            ->inLocale($locale)
            ->whereIn('article_id', Article::query()->where('hero_image', 'like', "articles/launch/{$key}.%")->select('id'))
            ->sole();
    }

    public function test_it_publishes_every_story_in_both_languages_under_the_desk_byline(): void
    {
        $result = $this->importFixture();

        $this->assertSame(array_keys(self::PICTURES), $result->imported);
        $this->assertSame([], $result->skipped);
        $this->assertTrue($result->successful());
        $this->assertTrue($result->createdByline);
        $this->assertSame(4, $result->createdTags);

        $this->assertSame(3, Article::count());
        $this->assertSame(6, ArticleTranslation::count());
        $this->assertSame(3, ArticleTranslation::inLocale(Locale::Ar)->count());
        $this->assertSame(3, ArticleTranslation::inLocale(Locale::En)->count());
        $this->assertSame(6, ArticleTranslation::visible()->count(), 'live for readers, not merely marked published');
        $this->assertSame([TranslationStatus::Published->value], DB::table('article_translations')->distinct()->pluck('status')->all());

        $desk = User::query()->where('email', LaunchContentImporter::BYLINE_EMAIL)->sole();
        $this->assertSame('desk@sisi.news', $desk->email);
        $this->assertSame('sisi.news Desk', $desk->name);
        $this->assertSame('تحرير سيسي نيوز', $desk->name_ar);
        $this->assertSame(Role::Journalist, $desk->role);
        $this->assertFalse($desk->is_active);
        $this->assertSame(3, $desk->articles()->count(), 'every imported article is bylined to the desk');
        $this->assertSame(2, User::count(), 'only the admin and the desk exist');
    }

    public function test_each_article_gets_its_section_flag_picture_and_credit_line(): void
    {
        $this->importFixture();

        $expected = [
            'nile-corniche-evening' => ['egypt', true, 'Photo: Test Author / Wikimedia Commons / CC BY-SA 4.0', 'تصوير: كاتب الاختبار / ويكيميديا كومنز / CC BY-SA 4.0'],
            'gulf-trade-talks' => ['business', false, 'Photo: Other Author / Wikimedia Commons / CC BY 2.0', 'تصوير: مصور آخر / ويكيميديا كومنز / CC BY 2.0'],
            'museum-after-dark' => ['culture', false, 'Photo: Third Author / Wikimedia Commons / CC0', 'تصوير: مصور ثالث / ويكيميديا كومنز / CC0'],
        ];

        foreach ($expected as $key => [$section, $featured, $captionEn, $captionAr]) {
            $article = Article::query()->where('hero_image', self::PICTURES[$key])->with('category.translations')->sole();

            $this->assertSame($section, $article->category->translations->firstWhere('locale', Locale::En)->slug, $key);
            $this->assertSame($featured, $article->is_featured, $key);
            $this->assertFalse($article->is_breaking);
            $this->assertSame($captionEn, $this->translation($key, Locale::En)->hero_caption);
            $this->assertSame($captionAr, $this->translation($key, Locale::Ar)->hero_caption);
        }
    }

    public function test_pictures_are_copied_unchanged_with_their_extension_and_get_webp_variants(): void
    {
        $this->importFixture();

        $disk = Storage::disk('public');
        $originals = ['nile-corniche-evening' => 'nile-corniche.jpg', 'gulf-trade-talks' => 'gulf-trade.webp', 'museum-after-dark' => 'museum.jpg'];

        foreach (self::PICTURES as $key => $path) {
            $disk->assertExists($path);
            $this->assertSame(sha1_file($this->fixtureDirectory().'/images/'.$originals[$key]), sha1($disk->get($path)), "{$key} is copied byte for byte");

            foreach (array_keys(HeroImages::SIZES) as $size) {
                $disk->assertExists(HeroImages::variantPath($path, $size));
            }
        }

        // 3 pictures + 3 WebP sizes each, and nothing else (no credits.json, no stray copies).
        $this->assertCount(3 + 9, $disk->allFiles());
    }

    public function test_translations_get_slugs_meta_descriptions_and_text_as_written(): void
    {
        $this->importFixture();

        $first = $this->translation('nile-corniche-evening', Locale::En);
        $this->assertSame('Evening on the Nile Corniche', $first->title);
        $this->assertSame('evening-on-the-nile-corniche', $first->slug, 'a missing slug is made by Slugger');
        $this->assertSame("Cairo's riverside promenade fills up as the heat breaks.", $first->excerpt);
        $this->assertStringContainsString('<strong>Corniche</strong>', $first->body);

        $this->assertSame('مساء-على-كورنيش-النيل', $this->translation('nile-corniche-evening', Locale::Ar)->slug, 'Arabic slugs stay Arabic');
        $this->assertSame('museum-after-dark', $this->translation('museum-after-dark', Locale::En)->slug, 'a given slug is kept');

        $this->assertSame(
            'Delegations meet in Cairo to discuss logistics and customs.',
            $this->translation('gulf-trade-talks', Locale::En)->meta_description,
            'without a meta_description the excerpt stands in',
        );
        $this->assertSame("Cairo's riverside promenade fills up as the heat breaks.", $first->meta_description);
    }

    public function test_tags_are_shared_between_stories_and_exist_in_both_languages(): void
    {
        $this->importFixture();

        $this->assertSame(4, Tag::count());
        $this->assertSame(8, TagTranslation::count());
        foreach (Tag::with('translations')->get() as $tag) {
            $this->assertEqualsCanonicalizing(['ar', 'en'], $tag->translations->map(fn ($t) => $t->locale->value)->all());
        }

        $cairo = TagTranslation::query()->where('locale', 'en')->where('slug', 'cairo')->sole()->tag;
        $this->assertSame('القاهرة', $cairo->translations->firstWhere('locale', Locale::Ar)->name);
        $this->assertSame(2, $cairo->articles()->count(), 'two stories share one Cairo tag');
        $this->assertSame(5, DB::table('article_tag')->count());
    }

    public function test_an_existing_tag_is_reused_instead_of_duplicated(): void
    {
        $trade = app(TagWriter::class)->findOrCreate('Trade', 'التجارة');

        $result = $this->importFixture();

        $this->assertSame(4, Tag::count());
        $this->assertSame(3, $result->createdTags, 'Trade already existed');
        $this->assertSame(1, TagTranslation::query()->where('locale', 'en')->where('slug', 'trade')->count());
        $this->assertSame(1, $trade->articles()->count());
    }

    public function test_publication_dates_are_staggered_back_from_the_moment_of_the_run(): void
    {
        $this->importFixture();

        $expected = ['nile-corniche-evening' => 2, 'gulf-trade-talks' => 9, 'museum-after-dark' => 30];
        foreach ($expected as $key => $hours) {
            foreach (Locale::cases() as $locale) {
                $this->assertTrue(
                    Carbon::parse(self::NOW)->subHours($hours)->equalTo($this->translation($key, $locale)->published_at),
                    "{$key} ({$locale->value}) goes live {$hours} hours before the run",
                );
            }
        }

        $newestFirst = ArticleTranslation::visible()->inLocale(Locale::En)->orderByDesc('published_at')->with('article')->get();
        $this->assertSame(array_values(self::PICTURES), $newestFirst->pluck('article.hero_image')->all(), 'the home page order follows published_hours_ago');
    }

    public function test_the_admin_who_ran_the_import_is_the_one_on_the_audit_trail(): void
    {
        $admin = $this->admin();
        $this->importFixture($admin);

        $this->assertSame(12, ArticleStatusChange::count(), 'submit and approve for each of six translations');
        $this->assertSame([$admin->id], ArticleStatusChange::query()->distinct()->pluck('user_id')->all());

        foreach (ArticleTranslation::with('statusChanges')->get() as $translation) {
            $trail = $translation->statusChanges->sortBy('id')->values();

            $this->assertSame([Transition::Submit, Transition::Approve], $trail->pluck('transition')->all());
            $this->assertSame(TranslationStatus::Draft, $trail[0]->from_status);
            $this->assertSame(TranslationStatus::InReview, $trail[0]->to_status);
            $this->assertSame(TranslationStatus::Published, $trail[1]->to_status);
        }
    }

    public function test_a_script_in_a_body_does_not_survive(): void
    {
        $this->importFixture();

        $body = $this->translation('museum-after-dark', Locale::En)->body;

        foreach (['<script', 'alert', 'xss-marker', 'onclick', 'onerror', 'steal'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $body);
        }
        $this->assertStringContainsString('Evening tickets sold out within a day.', $body);
        $this->assertStringContainsString('Curators call it a turning point.', $body);
    }

    public function test_running_it_twice_changes_nothing_and_says_so(): void
    {
        $admin = $this->admin();
        $this->importFixture($admin);
        $first = $this->rowCounts();
        $publishedAt = ArticleTranslation::orderBy('id')->pluck('published_at', 'id')->map->toDateTimeString()->all();

        $this->travelTo(Carbon::parse(self::NOW)->addHours(5));
        $report = [];
        $again = $this->importer()->import(
            LaunchContentPackage::load($this->fixtureDirectory()),
            $admin,
            function (string $level, string $message) use (&$report): void {
                $report[] = $message;
            },
        );

        $this->assertSame([], $again->imported);
        $this->assertSame(array_keys(self::PICTURES), $again->skipped);
        $this->assertFalse($again->createdByline);
        $this->assertSame(0, $again->createdTags);
        $this->assertSame($first, $this->rowCounts());
        $this->assertSame($publishedAt, ArticleTranslation::orderBy('id')->pluck('published_at', 'id')->map->toDateTimeString()->all(), 'dates are not reset');
        $this->assertContains('Skipping gulf-trade-talks: already imported.', $report);
    }

    public function test_a_story_an_editor_deleted_is_not_brought_back(): void
    {
        $admin = $this->admin();
        $this->importFixture($admin);

        Article::query()->where('hero_image', self::PICTURES['museum-after-dark'])->sole()->delete();
        $before = $this->rowCounts();

        $again = $this->importFixture($admin);

        $this->assertSame([], $again->imported);
        $this->assertCount(3, $again->skipped);
        $this->assertSame($before, $this->rowCounts());
        $this->assertSame(2, Article::count());
    }

    public function test_a_story_that_fails_is_rolled_back_with_its_files_and_the_rerun_completes_the_rest(): void
    {
        $admin = $this->admin();
        Log::spy();

        $failing = 'Gulf and Egyptian Firms Open Trade Talks';
        $event = 'eloquent.creating: '.ArticleTranslation::class;
        Event::listen($event, function (ArticleTranslation $translation) use ($failing): void {
            if ($translation->title === $failing) {
                throw new RuntimeException('database went away (simulated)');
            }
        });

        $first = $this->importFixture($admin);

        $this->assertSame(['nile-corniche-evening', 'museum-after-dark'], $first->imported);
        $this->assertSame(['gulf-trade-talks' => 'database went away (simulated)'], $first->failed);
        $this->assertFalse($first->successful());
        Log::shouldHaveReceived('error')->once();

        $this->assertSame(2, Article::count());
        $this->assertSame(4, ArticleTranslation::count(), 'no half-written story');
        $this->assertSame(0, Article::where('hero_image', self::PICTURES['gulf-trade-talks'])->count());
        $this->assertSame(0, TagTranslation::where('slug', 'trade')->count(), 'its tags are rolled back with it');
        $disk = Storage::disk('public');
        $disk->assertMissing(self::PICTURES['gulf-trade-talks']);
        foreach (array_keys(HeroImages::SIZES) as $size) {
            $disk->assertMissing(HeroImages::variantPath(self::PICTURES['gulf-trade-talks'], $size));
        }
        $this->assertSame(8, ArticleStatusChange::count());

        Event::forget($event);
        $second = $this->importFixture($admin);

        $this->assertSame(['gulf-trade-talks'], $second->imported);
        $this->assertSame(['nile-corniche-evening', 'museum-after-dark'], $second->skipped);
        $this->assertTrue($second->successful());
        $this->assertSame(3, Article::count());
        $this->assertSame(6, ArticleTranslation::visible()->count());
        $this->assertSame(4, Tag::count());
        $disk->assertExists(self::PICTURES['gulf-trade-talks']);
        $this->assertSame(2, User::count(), 'the byline is created once');
    }

    public function test_a_dry_run_reports_the_plan_and_writes_nothing(): void
    {
        $this->admin();
        $before = $this->rowCounts();
        $report = [];

        $result = $this->importer()->dryRun(
            LaunchContentPackage::load($this->fixtureDirectory()),
            function (string $level, string $message) use (&$report): void {
                $report[] = $message;
            },
        );

        $this->assertSame(array_keys(self::PICTURES), $result->imported);
        $this->assertTrue($result->createdByline, 'it would create the desk account');
        $this->assertSame($before, $this->rowCounts());
        $this->assertFalse(User::where('email', 'desk@sisi.news')->exists());
        $this->assertSame([], Storage::disk('public')->allFiles());

        $this->assertStringContainsString('would create the inactive staff account desk@sisi.news', $report[0]);
        $this->assertStringContainsString('Would import nile-corniche-evening: egypt, featured, 2 tags, published 2 h before the run', $report[1]);
        $this->assertStringContainsString('images/nile-corniche.jpg -> articles/launch/nile-corniche-evening.jpg', $report[1]);
    }

    public function test_a_dry_run_after_an_import_would_skip_everything(): void
    {
        $this->importFixture();

        $result = $this->importer()->dryRun(LaunchContentPackage::load($this->fixtureDirectory()));

        $this->assertSame([], $result->imported);
        $this->assertCount(3, $result->skipped);
        $this->assertFalse($result->createdByline);
    }

    /** @return array<string, array{string}> */
    public static function unfitPublishers(): array
    {
        return [
            'an editor' => ['editor'],
            'a journalist' => ['journalist'],
            'a deactivated admin' => ['inactive'],
        ];
    }

    #[DataProvider('unfitPublishers')]
    public function test_only_an_active_admin_may_publish_and_a_refusal_writes_nothing(string $kind): void
    {
        $publisher = $kind === 'inactive' ? User::factory()->admin()->inactive()->create() : User::factory()->{$kind}()->create();
        $before = $this->rowCounts();

        try {
            $this->importFixture($publisher);
            $this->fail('The import ran.');
        } catch (LaunchImportException $e) {
            $this->assertNotNull(LaunchContentImporter::publisherProblem($publisher, $publisher->email));
        }

        $this->assertSame($before, $this->rowCounts());
        $this->assertFalse(User::where('email', 'desk@sisi.news')->exists());
    }

    public function test_publisher_problems_name_the_account_and_what_is_wrong_with_it(): void
    {
        $this->assertStringContainsString('There is no account for ghost@sisi.news', LaunchContentImporter::publisherProblem(null, 'ghost@sisi.news'));
        $this->assertStringContainsString('is a editor, not an admin', LaunchContentImporter::publisherProblem(User::factory()->editor()->make(['email' => 'e@sisi.news']), 'e@sisi.news'));
        $this->assertStringContainsString('deactivated', LaunchContentImporter::publisherProblem(User::factory()->admin()->inactive()->make(['email' => 'a@sisi.news']), 'a@sisi.news'));
        $this->assertNull(LaunchContentImporter::publisherProblem(User::factory()->admin()->make(), 'ok@sisi.news'));
    }

    public function test_it_refuses_content_with_problems_before_writing_anything(): void
    {
        $directory = $this->contentDirectory(function (array $data): array {
            unset($data['stories'][2]['image']);

            return $data;
        });
        $admin = $this->admin();
        $before = $this->rowCounts();

        try {
            $this->importFixture($admin, $directory);
            $this->fail('The import ran.');
        } catch (LaunchImportException $e) {
            $this->assertStringContainsString('story #3 (museum-after-dark): image is required', $e->getMessage());
        }

        $this->assertSame($before, $this->rowCounts(), 'the two good stories were not imported either');
        $this->assertFalse(User::where('email', 'desk@sisi.news')->exists());
    }

    public function test_an_import_cannot_start_while_another_one_holds_the_lock(): void
    {
        $this->admin();
        $held = Cache::lock(LaunchContentImporter::LOCK, 60);
        $this->assertTrue($held->get());

        try {
            $this->importFixture(User::where('email', 'chief@sisi.news')->sole());
            $this->fail('The import ran while another one was running.');
        } catch (LaunchImportException $e) {
            $this->assertStringContainsString('Another launch-content import is running', $e->getMessage());
        } finally {
            $held->release();
        }

        $this->assertSame(0, Article::count());

        // And the lock is released after a normal run.
        $this->importFixture(User::where('email', 'chief@sisi.news')->sole());
        $this->assertSame(3, Article::count());
    }

    public function test_an_existing_desk_account_is_reused_untouched_and_an_active_one_is_flagged(): void
    {
        $desk = User::factory()->journalist()->create(['email' => 'desk@sisi.news', 'name' => 'Real Person']);
        $hash = $desk->password;
        $report = [];

        $this->importer()->import(
            LaunchContentPackage::load($this->fixtureDirectory()),
            $this->admin(),
            function (string $level, string $message) use (&$report): void {
                $report[] = [$level, $message];
            },
        );

        $desk->refresh();
        $this->assertSame('Real Person', $desk->name);
        $this->assertSame($hash, $desk->password);
        $this->assertTrue($desk->is_active, 'importing does not silently lock somebody out');
        $this->assertSame(3, $desk->articles()->count());
        $this->assertSame(2, User::count());
        $this->assertContains('warn', array_column(array_filter($report, fn (array $r) => str_contains($r[1], 'active account')), 0));
    }

    public function test_the_desk_cannot_sign_in_or_enter_the_panel_whatever_its_password(): void
    {
        $this->importFixture();
        $desk = User::query()->where('email', 'desk@sisi.news')->sole();
        app()->setLocale('en');
        Filament::setCurrentPanel('admin');

        $this->assertFalse($desk->is_active);
        $this->assertFalse($desk->canAccessPanel(Filament::getPanel('admin')));
        $this->actingAs($desk)->get('/admin')->assertForbidden();
        $this->actingAs($desk)->get('/admin/articles')->assertForbidden();
        $this->app['auth']->forgetGuards();

        // The password is random and unknown, so no common guess opens it.
        foreach (['password', 'desk@sisi.news', 'sisi.news', 'sisi.news Desk', 'secret', 'CorrectHorse42'] as $guess) {
            $this->assertFalse(Hash::check($guess, $desk->password), $guess);
        }
        $this->assertSame(60, strlen($desk->password), 'a bcrypt hash, never a plain value');

        // Even if somebody learned or reset the password, the inactive flag still shuts the door.
        $desk->forceFill(['password' => 'CorrectHorse42'])->save();
        Livewire::test(Login::class)
            ->fillForm(['email' => 'desk@sisi.news', 'password' => 'CorrectHorse42'])
            ->call('authenticate')
            ->assertHasFormErrors(['email']);
        $this->assertGuest();
    }
}
