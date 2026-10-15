<?php

namespace Tests\Feature\Seeders;

use App\Models\Page;
use App\Models\PageTranslation;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\PageSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class PageSeederTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    private array $temporary = [];

    protected function tearDown(): void
    {
        foreach ($this->temporary as $file) {
            @unlink($file);
        }

        parent::tearDown();
    }

    private function fixture(): string
    {
        return base_path('tests/fixtures/pages/pages.json');
    }

    private function seed_(?string $path = null): void
    {
        (new PageSeeder($path ?? $this->fixture()))->setContainer($this->app)->__invoke();
    }

    /** @return array<string, mixed> the fixture, decoded */
    private function content(): array
    {
        return json_decode((string) file_get_contents($this->fixture()), true, flags: JSON_THROW_ON_ERROR);
    }

    /** Writes $content (array to encode, or raw text) to a temporary file and returns its path. */
    private function write(array|string $content): string
    {
        $path = tempnam(sys_get_temp_dir(), 'pages');
        file_put_contents($path, is_array($content) ? json_encode($content, JSON_UNESCAPED_UNICODE) : $content);

        return $this->temporary[] = $path;
    }

    private function translation(string $slug, string $locale): PageTranslation
    {
        return PageTranslation::query()
            ->whereHas('page', fn ($q) => $q->where('slug', $slug))
            ->where('locale', $locale)
            ->firstOrFail();
    }

    private function failure(string $path): string
    {
        try {
            $this->seed_($path);
        } catch (RuntimeException $e) {
            return $e->getMessage();
        }

        $this->fail('The seeder accepted a file it should have refused.');
    }

    // --- loading ---------------------------------------------------------------------------

    public function test_it_creates_each_page_with_both_published_languages_and_its_flags(): void
    {
        $this->seed_();

        $this->assertSame(['about', 'terms'], Page::query()->orderBy('sort_order')->pluck('slug')->all());
        $about = Page::query()->where('slug', 'about')->firstOrFail();
        $terms = Page::query()->where('slug', 'terms')->firstOrFail();
        $this->assertSame(1, $about->sort_order);
        $this->assertTrue($about->show_in_footer);
        $this->assertSame(5, $terms->sort_order);
        $this->assertFalse($terms->show_in_footer);

        $this->assertSame(4, PageTranslation::query()->count());
        $this->assertSame(4, PageTranslation::query()->where('is_published', true)->count());

        $en = $this->translation('about', 'en');
        $this->assertSame('About us', $en->title);
        $this->assertSame('Who we are and how the newsroom works.', $en->meta_description);
        $this->assertStringContainsString('<h2>How we work</h2>', $en->body);
        $this->assertStringContainsString('[[contact]]', $en->body, 'the token is stored as written and filled in at render time');
        $this->assertSame('من نحن', $this->translation('about', 'ar')->title);
    }

    public function test_the_seeded_pages_are_public_in_both_languages(): void
    {
        $this->seed_();

        $this->get('/en/page/about')->assertOk()->assertSee('About us');
        $this->get('/ar/page/about')->assertOk()->assertSee('من نحن');
        $this->get('/en')->assertSee('About us')->assertDontSee('Terms of use', false);
    }

    public function test_it_sanitizes_the_body_like_every_other_write(): void
    {
        $content = $this->content();
        $content['pages'][0]['en']['body'] = '<p>Safe</p><script>alert(1)</script><a href="javascript:alert(2)">x</a>';

        $this->seed_($this->write($content));

        $body = $this->translation('about', 'en')->body;
        $this->assertStringNotContainsString('<script', $body);
        $this->assertStringNotContainsString('javascript:', $body);
        $this->assertStringContainsString('Safe', $body);
    }

    public function test_it_reads_a_file_that_starts_with_a_byte_order_mark(): void
    {
        $this->seed_($this->write("\xEF\xBB\xBF".(string) file_get_contents($this->fixture())));

        $this->assertSame(2, Page::query()->count());
    }

    // --- idempotence and never overwriting -------------------------------------------------

    public function test_running_it_twice_changes_nothing(): void
    {
        $this->seed_();
        $before = [Page::query()->orderBy('id')->get()->toArray(), PageTranslation::query()->orderBy('id')->get()->toArray()];

        $this->seed_();

        $this->assertSame(2, Page::query()->count());
        $this->assertSame(4, PageTranslation::query()->count());
        $this->assertEquals($before, [Page::query()->orderBy('id')->get()->toArray(), PageTranslation::query()->orderBy('id')->get()->toArray()]);
    }

    public function test_it_never_overwrites_a_page_an_editor_has_changed(): void
    {
        $this->seed_();
        $about = Page::query()->where('slug', 'about')->firstOrFail();
        $about->update(['sort_order' => 42, 'show_in_footer' => false]);
        $this->translation('about', 'en')->update([
            'title' => 'About the newsroom (edited)',
            'body' => '<p>Rewritten by an editor.</p>',
            'meta_description' => 'Edited description.',
            'is_published' => false,
        ]);
        $this->translation('about', 'ar')->update(['title' => 'عنوان معدّل']);

        $this->seed_();

        $about->refresh();
        $this->assertSame(42, $about->sort_order);
        $this->assertFalse($about->show_in_footer);
        $en = $this->translation('about', 'en');
        $this->assertSame('About the newsroom (edited)', $en->title);
        $this->assertSame('<p>Rewritten by an editor.</p>', $en->body);
        $this->assertSame('Edited description.', $en->meta_description);
        $this->assertFalse($en->is_published, 'an unpublished language stays unpublished');
        $this->assertSame('عنوان معدّل', $this->translation('about', 'ar')->title);
    }

    public function test_a_page_deleted_by_an_administrator_is_created_again_only_because_its_slug_is_new(): void
    {
        $this->seed_();
        Page::query()->where('slug', 'terms')->firstOrFail()->delete();

        $this->seed_();

        $this->assertSame(2, Page::query()->count());
        $this->assertSame(4, PageTranslation::query()->count());
    }

    public function test_a_new_slug_in_the_file_is_added_beside_the_untouched_existing_pages(): void
    {
        $this->seed_();
        $this->translation('about', 'en')->update(['title' => 'Edited']);
        $content = $this->content();
        $content['pages'][] = [
            'slug' => 'ownership',
            'sort_order' => 2,
            'show_in_footer' => true,
            'en' => ['title' => 'Ownership', 'meta_description' => 'Who owns us.', 'body' => '<p>Owners.</p>'],
            'ar' => ['title' => 'الملكية', 'meta_description' => 'من يملكنا.', 'body' => '<p>المالكون.</p>'],
        ];

        $this->seed_($this->write($content));

        $this->assertSame(3, Page::query()->count());
        $this->assertSame(6, PageTranslation::query()->count());
        $this->assertSame('Edited', $this->translation('about', 'en')->title);
        $this->assertSame('Ownership', $this->translation('ownership', 'en')->title);
    }

    public function test_the_database_seeder_runs_it_after_the_categories_and_stays_safe_to_repeat(): void
    {
        $this->app->bind(PageSeeder::class, fn () => new PageSeeder($this->fixture()));

        $this->seed(DatabaseSeeder::class);
        $this->translation('about', 'en')->update(['title' => 'Edited']);
        $this->seed(DatabaseSeeder::class);

        $this->assertSame(6, DB::table('categories')->count());
        $this->assertSame(2, Page::query()->count());
        $this->assertSame('Edited', $this->translation('about', 'en')->title);

        $source = (string) file_get_contents(database_path('seeders/DatabaseSeeder.php'));
        $this->assertLessThan(strpos($source, 'PageSeeder::class'), strpos($source, 'CategorySeeder::class'), 'PageSeeder runs after CategorySeeder');
    }

    // --- failing loudly --------------------------------------------------------------------

    public function test_a_missing_file_fails_with_a_clear_message_and_writes_nothing(): void
    {
        $message = $this->failure(sys_get_temp_dir().'/definitely-not-here-pages.json');

        $this->assertStringContainsString('not found or cannot be read', $message);
        $this->assertStringContainsString('database/pages/pages.json', $message);
        $this->assertSame(0, Page::query()->count());
    }

    public function test_invalid_json_fails_with_a_clear_message_and_writes_nothing(): void
    {
        $message = $this->failure($this->write('{"pages": [ {"slug": "about", '));

        $this->assertStringContainsString('not valid JSON', $message);
        $this->assertSame(0, Page::query()->count());
    }

    #[DataProvider('wrongShapes')]
    public function test_a_file_of_the_wrong_shape_is_refused(string $json): void
    {
        $message = $this->failure($this->write($json));

        $this->assertStringContainsString('non-empty "pages" list', $message);
        $this->assertSame(0, Page::query()->count());
    }

    public static function wrongShapes(): array
    {
        return [
            'empty object' => ['{}'],
            'a list' => ['[]'],
            'empty pages' => ['{"pages": []}'],
            'pages is an object' => ['{"pages": {"about": {}}}'],
            'a string' => ['"pages"'],
            'null' => ['null'],
        ];
    }

    /** @param  callable(array<string, mixed>): array<string, mixed>  $break */
    #[DataProvider('brokenPages')]
    public function test_a_problem_anywhere_refuses_the_whole_file_and_names_it(callable $break, string $expected): void
    {
        $content = $this->content();
        $content['pages'][1] = $break($content['pages'][1]);

        $message = $this->failure($this->write($content));

        $this->assertStringContainsString($expected, $message);
        $this->assertSame(0, Page::query()->count(), 'the valid first page was not written either');
        $this->assertSame(0, PageTranslation::query()->count());
    }

    public static function brokenPages(): array
    {
        return [
            'bad slug' => [fn (array $p) => [...$p, 'slug' => 'Terms Of Use'], '"slug" must be lowercase kebab-case'],
            'slug too long' => [fn (array $p) => [...$p, 'slug' => str_repeat('a', 81)], '"slug" must be lowercase kebab-case'],
            'duplicate slug' => [fn (array $p) => [...$p, 'slug' => 'about'], 'page "about" appears more than once'],
            'sort order as text' => [fn (array $p) => [...$p, 'sort_order' => '5'], '"sort_order" must be an integer'],
            'negative sort order' => [fn (array $p) => [...$p, 'sort_order' => -1], '"sort_order" must be an integer'],
            'footer flag as text' => [fn (array $p) => [...$p, 'show_in_footer' => 'yes'], '"show_in_footer" must be true or false'],
            'missing arabic' => [fn (array $p) => array_diff_key($p, ['ar' => 1]), 'page "terms" [ar]: the "ar" block is missing'],
            'missing english' => [fn (array $p) => array_diff_key($p, ['en' => 1]), 'page "terms" [en]: the "en" block is missing'],
            'blank title' => [fn (array $p) => [...$p, 'en' => [...$p['en'], 'title' => '  ']], '[en]: "title" must be text'],
            'title too long' => [fn (array $p) => [...$p, 'ar' => [...$p['ar'], 'title' => str_repeat('م', 256)]], '[ar]: "title" must be text'],
            'missing description' => [fn (array $p) => [...$p, 'en' => array_diff_key($p['en'], ['meta_description' => 1])], '[en]: "meta_description" must be text'],
            'description too long' => [fn (array $p) => [...$p, 'en' => [...$p['en'], 'meta_description' => str_repeat('x', 301)]], '[en]: "meta_description" must be text'],
            'empty body' => [fn (array $p) => [...$p, 'ar' => [...$p['ar'], 'body' => '']], '[ar]: "body" must be HTML with some text'],
            'body with only markup' => [fn (array $p) => [...$p, 'en' => [...$p['en'], 'body' => '<p></p><script>alert(1)</script>']], '[en]: "body" must be HTML with some text'],
            'real email in body' => [fn (array $p) => [...$p, 'en' => [...$p['en'], 'body' => '<p>Write to desk@sisi.news.</p>']], 'contains an e-mail address; write [[contact]]'],
            'real email in a mailto link' => [fn (array $p) => [...$p, 'ar' => [...$p['ar'], 'body' => '<p><a href="mailto:desk@sisi.news">راسلونا</a></p>']], 'contains an e-mail address'],
        ];
    }

    public function test_every_problem_is_reported_at_once(): void
    {
        $content = $this->content();
        $content['pages'][0]['slug'] = 'Bad Slug';
        $content['pages'][1]['sort_order'] = 'x';
        unset($content['pages'][1]['ar']);

        $message = $this->failure($this->write($content));

        $this->assertStringContainsString('"slug" must be lowercase kebab-case', $message);
        $this->assertStringContainsString('"sort_order" must be an integer', $message);
        $this->assertStringContainsString('the "ar" block is missing', $message);
    }

    public function test_a_description_over_160_characters_only_warns(): void
    {
        $content = $this->content();
        $content['pages'][0]['en']['meta_description'] = str_repeat('word ', 40);

        $this->seed_($this->write($content));

        $this->assertSame(2, Page::query()->count());
    }

    // --- the production file ---------------------------------------------------------------

    public function test_the_production_content_file_is_valid_and_seeds_the_five_core_pages(): void
    {
        $path = database_path('pages/pages.json');
        if (! is_file($path)) {
            $this->markTestSkipped('database/pages/pages.json has not been delivered yet.');
        }

        $this->seed_($path);

        $this->assertEqualsCanonicalizing(Page::CORE_SLUGS, Page::query()->pluck('slug')->all());
        $this->assertSame(10, PageTranslation::query()->where('is_published', true)->count());
        $this->assertSame(0, PageTranslation::query()->where('body', 'like', '%@%')->count(), 'no e-mail address in the data');
        Page::query()->with('translations')->get()->each(function (Page $page): void {
            foreach ($page->translations as $translation) {
                $this->assertLessThanOrEqual(160, mb_strlen((string) $translation->meta_description), "{$page->slug} [{$translation->locale->value}] description");
            }
        });

        foreach (Page::CORE_SLUGS as $slug) {
            $this->get("/en/page/{$slug}")->assertOk();
            $this->get("/ar/page/{$slug}")->assertOk();
        }
    }

    public function test_by_default_it_reads_database_pages_pages_json(): void
    {
        if (! is_file(database_path('pages/pages.json'))) {
            $this->markTestSkipped('database/pages/pages.json has not been delivered yet.');
        }

        (new PageSeeder)->setContainer($this->app)->__invoke();

        $this->assertGreaterThan(0, Page::query()->count());
    }
}
