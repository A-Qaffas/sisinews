<?php

namespace Tests\Feature\Seeders;

use App\Enums\Locale;
use App\Models\Governorate;
use App\Models\LiveStream;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\GovernorateSeeder;
use Database\Seeders\PageSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class GovernorateSeederTest extends TestCase
{
    use RefreshDatabase;

    private const EXPECTED_SLUGS = [
        'cairo', 'giza', 'alexandria', 'qalyubia', 'port-said', 'suez', 'dakahlia', 'sharqia', 'gharbia', 'monufia',
        'beheira', 'kafr-el-sheikh', 'damietta', 'ismailia', 'fayoum', 'beni-suef', 'minya', 'asyut', 'sohag', 'qena',
        'luxor', 'aswan', 'red-sea', 'new-valley', 'matrouh', 'north-sinai', 'south-sinai',
    ];

    private function seedGovernorates(): void
    {
        $this->seed(GovernorateSeeder::class);
    }

    /** @return list<object> */
    private function rows(): array
    {
        return DB::table('governorates')->orderBy('sort_order')->get()->all();
    }

    public function test_it_creates_the_27_governorates_in_the_agreed_order(): void
    {
        $this->seedGovernorates();

        $rows = $this->rows();
        $this->assertCount(27, $rows);
        $this->assertSame(self::EXPECTED_SLUGS, array_column($rows, 'slug'));
        $this->assertSame(range(1, 27), array_map(fn (object $row): int => (int) $row->sort_order, $rows));
    }

    public function test_every_slug_is_lowercase_kebab_case_and_unique(): void
    {
        $this->seedGovernorates();

        $slugs = array_column($this->rows(), 'slug');
        $this->assertSame($slugs, array_values(array_unique($slugs)));
        foreach ($slugs as $slug) {
            $this->assertMatchesRegularExpression('/\A[a-z]+(?:-[a-z]+)*\z/', $slug);
        }
    }

    public function test_every_governorate_has_an_arabic_and_an_english_name(): void
    {
        $this->seedGovernorates();

        foreach ($this->rows() as $row) {
            $this->assertNotSame('', trim($row->name_en), "{$row->slug} needs an English name");
            $this->assertMatchesRegularExpression('/\A[A-Za-z ]+\z/', $row->name_en, "{$row->slug}: the English name is Latin letters");
            $this->assertNotSame('', trim($row->name_ar), "{$row->slug} needs an Arabic name");
            $this->assertMatchesRegularExpression('/\A[\x{0600}-\x{06FF} ]+\z/u', $row->name_ar, "{$row->slug}: the Arabic name is Arabic letters");
        }
    }

    public function test_arabic_names_are_stored_and_read_back_correctly(): void
    {
        $this->seedGovernorates();

        $names = DB::table('governorates')->pluck('name_ar', 'slug')->all();
        $this->assertSame('القاهرة', $names['cairo']);
        $this->assertSame('الإسكندرية', $names['alexandria']);
        $this->assertSame('البحر الأحمر', $names['red-sea']);
        $this->assertSame('الوادي الجديد', $names['new-valley']);
        $this->assertSame('جنوب سيناء', $names['south-sinai']);

        $cairo = Governorate::query()->where('slug', 'cairo')->firstOrFail();
        $this->assertSame('Cairo', $cairo->name_en);
        $this->assertSame(12, mb_strlen('البحر الأحمر'), 'the text is whole characters, not mangled bytes');
        $this->assertSame('البحر الأحمر', Governorate::query()->where('slug', 'red-sea')->value('name_ar'));
    }

    public function test_the_two_word_names_read_naturally_in_english(): void
    {
        $this->seedGovernorates();

        $english = DB::table('governorates')->pluck('name_en', 'slug')->all();
        $this->assertSame('Kafr El Sheikh', $english['kafr-el-sheikh']);
        $this->assertSame('Port Said', $english['port-said']);
        $this->assertSame('Beni Suef', $english['beni-suef']);
        $this->assertSame('New Valley', $english['new-valley']);
        $this->assertSame('North Sinai', $english['north-sinai']);
    }

    public function test_running_it_twice_changes_nothing_and_never_duplicates(): void
    {
        $this->seedGovernorates();
        $before = $this->rows();

        $this->seedGovernorates();
        $this->seedGovernorates();

        $this->assertSame(27, DB::table('governorates')->count());
        $this->assertEquals($before, $this->rows(), 'same rows, same ids');
    }

    public function test_a_governorate_keeps_its_id_so_broadcasts_stay_attached_to_it(): void
    {
        $this->seedGovernorates();
        $luxor = Governorate::query()->where('slug', 'luxor')->firstOrFail();
        $stream = LiveStream::factory()->inGovernorate($luxor)->create();

        $this->seedGovernorates();

        $this->assertSame($luxor->getKey(), Governorate::query()->where('slug', 'luxor')->value('id'));
        $this->assertSame($luxor->getKey(), $stream->fresh()->governorate_id);
    }

    public function test_a_changed_name_or_position_is_put_right_on_the_next_run(): void
    {
        $this->seedGovernorates();
        DB::table('governorates')->where('slug', 'giza')->update(['name_en' => 'Gizah', 'name_ar' => 'x', 'sort_order' => 99]);

        $this->seedGovernorates();

        $giza = DB::table('governorates')->where('slug', 'giza')->first();
        $this->assertSame('Giza', $giza->name_en);
        $this->assertSame('الجيزة', $giza->name_ar);
        $this->assertSame(2, (int) $giza->sort_order);
        $this->assertSame(27, DB::table('governorates')->count());
    }

    public function test_it_leaves_a_row_it_does_not_know_about_alone(): void
    {
        DB::table('governorates')->insert(['slug' => 'extra', 'name_ar' => 'إضافية', 'name_en' => 'Extra', 'sort_order' => 50]);

        $this->seedGovernorates();

        $this->assertSame(28, DB::table('governorates')->count());
        $this->assertSame('Extra', DB::table('governorates')->where('slug', 'extra')->value('name_en'));
    }

    public function test_it_does_not_touch_the_broadcasts(): void
    {
        $stream = LiveStream::factory()->live()->withTranslations()->create();

        $this->seedGovernorates();

        $this->assertSame(1, LiveStream::query()->count());
        $this->assertSame(2, $stream->translations()->count());
        $this->assertTrue($stream->fresh()->canEnd());
    }

    public function test_the_database_seeder_runs_it_and_stays_safe_to_repeat(): void
    {
        $this->app->bind(PageSeeder::class, fn () => new PageSeeder(base_path('tests/fixtures/pages/pages.json')));

        $this->seed(DatabaseSeeder::class);
        $this->seed(DatabaseSeeder::class);

        $this->assertSame(27, DB::table('governorates')->count());

        $source = (string) file_get_contents(database_path('seeders/DatabaseSeeder.php'));
        $this->assertGreaterThan(strpos($source, 'PageSeeder::class'), strpos($source, 'GovernorateSeeder::class'), 'it runs after PageSeeder');
    }

    public function test_the_model_exposes_the_names_in_each_language(): void
    {
        $this->seedGovernorates();
        $cairo = Governorate::query()->where('slug', 'cairo')->firstOrFail();

        $this->assertSame('القاهرة', $cairo->nameIn(Locale::Ar));
        $this->assertSame('Cairo', $cairo->nameIn(Locale::En));
    }
}
