<?php

namespace Tests\Feature\Seeders;

use App\Models\CategoryTranslation;
use Closure;
use Database\Seeders\Support\LaunchContentPackage;
use Database\Seeders\Support\LaunchStory;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;

class LaunchContentPackageTest extends LaunchContentTest
{
    /** @param  (Closure(array<string, mixed>): array<string, mixed>)  $edit */
    private function problemsAfter(Closure $edit): array
    {
        return LaunchContentPackage::load($this->contentDirectory($edit))->problems();
    }

    public function test_the_fixture_is_a_valid_package(): void
    {
        $package = LaunchContentPackage::load($this->fixtureDirectory());

        $this->assertSame([], $package->problems());
        $this->assertSame([], $package->warnings());
        $this->assertTrue($package->isValid());
        $this->assertSame('sisi.news Desk', $package->deskName());
        $this->assertSame('تحرير سيسي نيوز', $package->deskNameAr());

        $stories = $package->stories();
        $this->assertSame(['nile-corniche-evening', 'gulf-trade-talks', 'museum-after-dark'], array_map(fn (LaunchStory $s) => $s->key, $stories));

        $first = $stories[0];
        $this->assertSame('egypt', $first->category);
        $this->assertTrue($first->featured);
        $this->assertSame(2, $first->hoursAgo);
        $this->assertSame('jpg', $first->extension);
        $this->assertSame('articles/launch/nile-corniche-evening.jpg', $first->imagePath());
        $this->assertSame([['en' => 'Cairo', 'ar' => 'القاهرة'], ['en' => 'Nile', 'ar' => 'النيل']], $first->tags);
        $this->assertFileExists($first->sourceFile);
        $this->assertSame('articles/launch/gulf-trade-talks.webp', $stories[1]->imagePath(), 'the extension is kept');
        $this->assertFalse($stories[2]->featured, 'featured defaults to false');
    }

    public function test_every_problem_is_reported_at_once(): void
    {
        $problems = $this->problemsAfter(function (array $data): array {
            $data['stories'][0]['category'] = 'sports';
            $data['stories'][1]['image'] = 'images/not-there.jpg';
            $data['stories'][2]['en']['title'] = '   ';
            $data['stories'][2]['key'] = 'nile-corniche-evening';
            unset($data['stories'][1]['ar']['excerpt']);
            $data['stories'][] = ['key' => 'half-a-story', 'category' => 'world', 'image' => 'images/museum.jpg', 'published_hours_ago' => 3, 'en' => $data['stories'][0]['en']];

            return $data;
        });

        $text = implode("\n", $problems);
        $this->assertStringContainsString('story #1 (nile-corniche-evening): category "sports" must be the English slug of an existing section (known: business, culture, egypt, politics, regional-affairs, world)', $text);
        $this->assertStringContainsString('story #2 (gulf-trade-talks): image file "images/not-there.jpg" does not exist', $text);
        $this->assertStringContainsString('story #2 (gulf-trade-talks), ar: excerpt is required and must not be empty', $text);
        $this->assertStringContainsString('story #3 (nile-corniche-evening): duplicate key, already used by story #1', $text);
        $this->assertStringContainsString('story #4 (half-a-story), ar: the ar text block is missing', $text);
        $this->assertGreaterThanOrEqual(5, count($problems));
    }

    public function test_a_package_with_problems_hands_out_no_stories(): void
    {
        $package = LaunchContentPackage::load($this->contentDirectory(function (array $data): array {
            $data['stories'][2]['category'] = 'sports';

            return $data;
        }));

        $this->assertFalse($package->isValid());
        $this->assertSame([], $package->stories(), 'two good stories are still not importable on their own');
    }

    /** @return array<string, array{Closure(array): array, string}> */
    public static function brokenStories(): array
    {
        $story = fn (Closure $edit) => function (array $data) use ($edit): array {
            $edit($data['stories'][0]);

            return $data;
        };
        $set = fn (string $path, mixed $value) => $story(function (array &$s) use ($path, $value): void {
            data_set($s, $path, $value);
        });
        $unset = fn (string $field) => $story(function (array &$s) use ($field): void {
            unset($s[$field]);
        });

        return [
            'key not kebab case' => [$set('key', 'Nile Corniche'), 'key must be unique lower-case kebab text'],
            'key missing' => [$unset('key'), 'story #1: key must be unique'],
            'category missing' => [$unset('category'), 'category is missing and must be the English slug'],
            'category in Arabic' => [$set('category', 'مصر'), 'must be the English slug of an existing section'],
            'image missing from the story' => [$unset('image'), 'image is required'],
            'image of an unsupported type' => [$set('image', 'images/credits.json'), 'must be a .jpg, .jpeg, .png or .webp file'],
            'image path climbs out of the directory' => [$set('image', '../outside.jpg'), 'must be a plain relative path'],
            'absolute image path' => [$set('image', '/etc/passwd.jpg'), 'must be a plain relative path'],
            'windows image path' => [$set('image', 'C:\\pictures\\a.jpg'), 'must be a plain relative path'],
            'featured as text' => [$set('featured', 'yes'), 'featured must be true or false'],
            'hours missing' => [$unset('published_hours_ago'), 'published_hours_ago must be a whole number'],
            'hours fractional' => [$set('published_hours_ago', 1.5), 'published_hours_ago must be a whole number'],
            'hours as text' => [$set('published_hours_ago', '6'), 'published_hours_ago must be a whole number'],
            'hours in the future' => [$set('published_hours_ago', -1), 'published_hours_ago must be a whole number'],
            'tags not a list' => [$set('tags', 'cairo'), 'tags must be a list'],
            'tag without Arabic' => [$set('tags.0', ['en' => 'Cairo']), 'tag #1: ar is required'],
            'tag without letters' => [$set('tags.0', ['en' => '!!!', 'ar' => 'القاهرة']), 'en name "!!!" has no letters or digits'],
            'English block missing' => [$unset('en'), 'the en text block is missing'],
            'title empty' => [$set('en.title', ''), 'en: title is required'],
            'title over 255 characters' => [$set('en.title', str_repeat('a', 256)), 'en: title is 256 characters long; the limit is 255'],
            'title with markup' => [$set('en.title', 'Evening <b>on</b> the Nile'), 'en: title must be plain text'],
            'title with an entity' => [$set('en.title', 'Fish &amp; chips'), 'en: title must be plain text'],
            'excerpt missing' => [$set('ar.excerpt', null), 'ar: excerpt is required'],
            'body missing' => [$set('en.body', null), 'en: body is required'],
            'body only a script' => [$set('en.body', '<script>alert(1)</script>'), 'en: body has no text left after sanitizing'],
            'credit line missing' => [$set('en.hero_caption', ''), 'en: hero_caption is required'],
            'credit line only in one language' => [$set('ar.hero_caption', null), 'ar: hero_caption is required'],
            'meta description too long' => [$set('en.meta_description', str_repeat('a', 301)), 'en: meta_description is 301 characters long; the limit is 300'],
            'slug without letters' => [$set('en.slug', '---'), 'en: slug must contain letters or digits'],
            'Arabic title in the English block' => [$set('en.title', 'مساء على كورنيش النيل'), 'en: title has no Latin letters'],
            'English title in the Arabic block' => [$set('ar.title', 'Evening on the Nile Corniche'), 'ar: title has no Arabic letters'],
            'English body in the Arabic block' => [$set('ar.body', '<p>Families share the Corniche.</p>'), 'ar: body has no Arabic letters'],
        ];
    }

    #[DataProvider('brokenStories')]
    public function test_each_rule_is_enforced_and_names_the_story(Closure $edit, string $expected): void
    {
        $problems = $this->problemsAfter($edit);

        $this->assertNotEmpty($problems, 'the package should be refused');
        $this->assertStringContainsString($expected, implode("\n", $problems));
    }

    public function test_the_desk_names_are_required(): void
    {
        $this->assertStringContainsString('desk must be an object', implode("\n", $this->problemsAfter(function (array $data): array {
            unset($data['desk']);

            return $data;
        })));

        $problems = implode("\n", $this->problemsAfter(function (array $data): array {
            $data['desk']['name_ar'] = '';

            return $data;
        }));
        $this->assertStringContainsString('desk: name_ar is required', $problems);
    }

    public function test_stories_must_be_a_non_empty_list(): void
    {
        foreach ([[], 'none', ['a' => 1]] as $stories) {
            $problems = $this->problemsAfter(function (array $data) use ($stories): array {
                $data['stories'] = $stories;

                return $data;
            });

            $this->assertContains('stories must be a non-empty list of stories.', $problems);
        }
    }

    public function test_a_story_that_is_not_an_object_is_reported_by_position(): void
    {
        $problems = $this->problemsAfter(function (array $data): array {
            $data['stories'][1] = 'oops';

            return $data;
        });

        $this->assertSame(['story #2 must be an object.'], $problems);
    }

    public function test_a_picture_that_is_not_an_image_is_refused_even_with_an_image_extension(): void
    {
        $directory = $this->contentDirectory();
        file_put_contents("{$directory}/images/museum.jpg", '<?php echo "not a picture";');

        $problems = LaunchContentPackage::load($directory)->problems();

        $this->assertCount(1, $problems);
        $this->assertStringContainsString('story #3 (museum-after-dark): "images/museum.jpg" is not a readable JPEG, PNG or WebP picture', $problems[0]);
    }

    public function test_a_symlinked_picture_cannot_pull_in_a_file_from_outside(): void
    {
        $directory = $this->contentDirectory();
        $outside = sys_get_temp_dir().'/sisi-outside-'.bin2hex(random_bytes(4));
        $files = new Filesystem;
        $files->copyDirectory($directory.'/images', $outside);
        $files->deleteDirectory($directory.'/images');
        $this->beforeApplicationDestroyed(function () use ($directory, $outside, $files): void {
            // Remove the link itself first, or deleting the directory would follow it.
            @rmdir($directory.'/images');
            @unlink($directory.'/images');
            $files->deleteDirectory($directory);
            $files->deleteDirectory($outside);
        });

        // A directory junction needs no privilege on Windows; a symlink is what a server would have.
        $linked = @symlink($outside, $directory.'/images')
            || (PHP_OS_FAMILY === 'Windows' && str_contains((string) shell_exec('cmd /c mklink /J "'.str_replace('/', '\\', $directory).'\\images" "'.str_replace('/', '\\', $outside).'" 2>&1'), 'Junction created'));
        if (! $linked) {
            $this->markTestSkipped('This system does not allow creating symbolic links or junctions.');
        }

        $problems = implode("\n", LaunchContentPackage::load($directory)->problems());

        $this->assertStringContainsString('resolves outside the content directory', $problems);
    }

    public function test_a_missing_or_unreadable_content_directory_is_one_clear_problem(): void
    {
        $missing = LaunchContentPackage::load(sys_get_temp_dir().'/sisi-no-such-content-directory');
        $this->assertCount(1, $missing->problems());
        $this->assertStringContainsString('content directory', $missing->problems()[0]);
        $this->assertStringContainsString('does not exist', $missing->problems()[0]);

        $empty = sys_get_temp_dir().'/sisi-empty-content-'.bin2hex(random_bytes(4));
        mkdir($empty);
        $this->beforeApplicationDestroyed(fn () => @rmdir($empty));
        $this->assertContains('stories.json is missing in '.$empty.'.', LaunchContentPackage::load($empty)->problems());
    }

    public function test_broken_json_is_reported_not_thrown(): void
    {
        $directory = $this->contentDirectory();
        file_put_contents("{$directory}/stories.json", '{"desk": ');

        $problems = LaunchContentPackage::load($directory)->problems();

        $this->assertCount(1, $problems);
        $this->assertStringContainsString('stories.json is not valid JSON', $problems[0]);

        file_put_contents("{$directory}/stories.json", '[1, 2]');
        $this->assertSame(['stories.json must be a JSON object.'], LaunchContentPackage::load($directory)->problems());
    }

    public function test_a_byte_order_mark_from_a_windows_editor_is_tolerated(): void
    {
        $directory = $this->contentDirectory();
        file_put_contents("{$directory}/stories.json", "\xEF\xBB\xBF".file_get_contents("{$directory}/stories.json"));

        $this->assertSame([], LaunchContentPackage::load($directory)->problems());
    }

    public function test_missing_sections_point_at_the_reference_seeder(): void
    {
        DB::table('category_translations')->delete();
        $this->assertSame(0, CategoryTranslation::count());

        $problems = implode("\n", LaunchContentPackage::load($this->fixtureDirectory())->problems());

        $this->assertStringContainsString('none exist yet, run "php artisan db:seed" first', $problems);
    }

    public function test_credits_trouble_and_oversized_pictures_warn_without_blocking(): void
    {
        $directory = $this->contentDirectory(function (array $data): array {
            $data['stories'][0]['source_note'] = 'kept for the desk';
            $data['stories'][1]['en']['subtitle'] = 'not a field';

            return $data;
        });
        unlink("{$directory}/images/credits.json");

        $wide = imagecreatetruecolor(1700, 60);
        imagejpeg($wide, "{$directory}/images/museum.jpg", 70);
        unset($wide);

        $package = LaunchContentPackage::load($directory);

        $this->assertTrue($package->isValid(), implode("\n", $package->problems()));
        $warnings = implode("\n", $package->warnings());
        $this->assertStringContainsString('images/credits.json is missing', $warnings);
        $this->assertStringContainsString('story #1 (nile-corniche-evening): unknown field "source_note" is ignored', $warnings);
        $this->assertStringContainsString('story #2 (gulf-trade-talks), en: unknown field "subtitle" is ignored', $warnings);
        $this->assertStringContainsString('"images/museum.jpg" is 1700 px wide', $warnings);
    }

    public function test_a_picture_without_a_credits_entry_warns(): void
    {
        $directory = $this->contentDirectory();
        $credits = json_decode((string) file_get_contents("{$directory}/images/credits.json"), true);
        unset($credits['images/gulf-trade.webp']);
        file_put_contents("{$directory}/images/credits.json", json_encode($credits));

        $package = LaunchContentPackage::load($directory);

        $this->assertTrue($package->isValid());
        $this->assertSame(['story #2 (gulf-trade-talks): no entry for "images/gulf-trade.webp" in images/credits.json.'], $package->warnings());
    }
}
