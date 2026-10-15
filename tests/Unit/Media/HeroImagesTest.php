<?php

namespace Tests\Unit\Media;

use App\Support\Media\GdEngine;
use App\Support\Media\HeroImages;
use App\Support\Media\ImageEngine;
use App\Support\Media\ImagickEngine;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use Tests\Unit\Media\Concerns\MakesImages;

class HeroImagesTest extends TestCase
{
    use MakesImages;

    private const PATH = 'articles/hero.jpg';

    /** Encoding a 20 megapixel JPEG takes seconds, so it is built once per run. */
    private static ?string $bigJpeg = null;

    private function bigJpeg(): string
    {
        return self::$bigJpeg ??= $this->jpegBytes(5000, 4000);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakePublicDisk();
    }

    private function store(string $bytes, string $path = self::PATH): string
    {
        Storage::disk('public')->put($path, $bytes);

        return $path;
    }

    /** @return array{0: int, 1: int, 2: \GdImage} */
    private function variant(string $size, string $path = self::PATH): array
    {
        $variant = HeroImages::variantPath($path, $size);
        Storage::disk('public')->assertExists($variant);

        return $this->decodeWebp(Storage::disk('public')->get($variant));
    }

    public function test_process_writes_three_webp_variants_next_to_the_original(): void
    {
        $this->store($this->jpegBytes(2000, 1500));

        HeroImages::process(self::PATH);

        $this->assertSame([480, 360], array_slice($this->variant('thumb'), 0, 2));
        $this->assertSame([800, 600], array_slice($this->variant('card'), 0, 2));
        $this->assertSame([1280, 960], array_slice($this->variant('full'), 0, 2));
        Storage::disk('public')->assertExists(self::PATH);
        Storage::disk('public')->assertExists('articles/variants/thumb/hero.jpg.webp');
    }

    public function test_variants_are_never_upscaled(): void
    {
        $this->store($this->jpegBytes(600, 400));

        HeroImages::process(self::PATH);

        $this->assertSame([480, 320], array_slice($this->variant('thumb'), 0, 2));
        $this->assertSame([600, 400], array_slice($this->variant('card'), 0, 2));
        $this->assertSame([600, 400], array_slice($this->variant('full'), 0, 2));
    }

    public function test_png_sources_are_supported(): void
    {
        $this->store($this->pngBytes(1000, 500), 'articles/hero.png');

        HeroImages::process('articles/hero.png');

        $this->assertSame([800, 400], array_slice($this->variant('card', 'articles/hero.png'), 0, 2));
    }

    public function test_process_is_idempotent_and_skips_fresh_variants(): void
    {
        $this->store($this->jpegBytes(1000, 700));
        HeroImages::process(self::PATH);

        $disk = Storage::disk('public');
        $variant = HeroImages::variantPath(self::PATH, 'card');
        $disk->put($variant, 'sentinel');
        $thumbBefore = $disk->get(HeroImages::variantPath(self::PATH, 'thumb'));

        HeroImages::process(self::PATH);

        $this->assertSame('sentinel', $disk->get($variant), 'a fresh variant must not be rewritten');
        $this->assertSame($thumbBefore, $disk->get(HeroImages::variantPath(self::PATH, 'thumb')));
    }

    public function test_only_missing_variants_are_rebuilt(): void
    {
        $this->store($this->jpegBytes(1000, 700));
        HeroImages::process(self::PATH);

        $disk = Storage::disk('public');
        $disk->put(HeroImages::variantPath(self::PATH, 'card'), 'sentinel');
        $disk->delete(HeroImages::variantPath(self::PATH, 'full'));

        HeroImages::process(self::PATH);

        $this->assertSame('sentinel', $disk->get(HeroImages::variantPath(self::PATH, 'card')));
        $this->assertSame(1000, $this->variant('full')[0]);
    }

    public function test_variants_older_than_a_replaced_original_are_rebuilt(): void
    {
        $this->store($this->jpegBytes(1000, 700));
        HeroImages::process(self::PATH);

        $disk = Storage::disk('public');
        $disk->put(HeroImages::variantPath(self::PATH, 'card'), 'stale');
        touch($disk->path(self::PATH), time() + 60);

        HeroImages::process(self::PATH);

        $this->assertSame(800, $this->variant('card')[0]);
    }

    public function test_orientation_is_honoured_for_a_sideways_photo(): void
    {
        $this->store($this->jpegBytes(600, 400, orientation: 6));

        HeroImages::process(self::PATH);

        [$width, $height] = $this->variant('full');
        $this->assertSame([400, 600], [$width, $height], 'a rotated photo is stored upright');
        // Never wider than the upright image, even though the stored pixels are 600 wide.
        $this->assertSame(400, $this->variant('card')[0]);
        $this->assertSame(400, $this->variant('thumb')[0]);
    }

    /** @return array<string, array{int, string}> orientation => where the top-left block of the stored pixels ends up */
    public static function orientations(): array
    {
        return [
            'normal' => [1, 'top-left'],
            'mirror horizontal' => [2, 'top-right'],
            'rotate 180' => [3, 'bottom-right'],
            'mirror vertical' => [4, 'bottom-left'],
            'transpose' => [5, 'top-left'],
            'rotate 90 cw' => [6, 'top-right'],
            'transverse' => [7, 'bottom-right'],
            'rotate 270 cw' => [8, 'bottom-left'],
        ];
    }

    #[DataProvider('orientations')]
    public function test_every_exif_orientation_lands_the_marker_in_the_right_corner(int $orientation, string $corner): void
    {
        $this->store($this->jpegBytes(400, 200, orientation: $orientation, marker: true));

        HeroImages::process(self::PATH);

        [$width, $height, $image] = $this->variant('full');
        $this->assertSame($orientation >= 5 ? [200, 400] : [400, 200], [$width, $height]);

        $inset = fn (int $size) => (int) round($size * 0.12);
        $points = [
            'top-left' => [$inset($width), $inset($height)],
            'top-right' => [$width - 1 - $inset($width), $inset($height)],
            'bottom-left' => [$inset($width), $height - 1 - $inset($height)],
            'bottom-right' => [$width - 1 - $inset($width), $height - 1 - $inset($height)],
        ];
        foreach ($points as $name => [$x, $y]) {
            $this->assertSame($name === $corner, $this->isRed($image, $x, $y), "{$name} corner for orientation {$orientation}");
        }
    }

    public function test_a_source_too_big_for_the_free_memory_is_refused_not_fatal(): void
    {
        $this->store($this->bigJpeg());
        Log::spy();

        $original = ini_get('memory_limit');
        // The decode alone needs ~80 MB (5000*4000*4); leave far less than that free.
        ini_set('memory_limit', (string) (memory_get_usage(true) + 40 * 1048576));
        try {
            HeroImages::process(self::PATH);
        } finally {
            ini_set('memory_limit', $original);
        }

        foreach (array_keys(HeroImages::SIZES) as $size) {
            Storage::disk('public')->assertMissing(HeroImages::variantPath(self::PATH, $size));
        }
        Storage::disk('public')->assertExists(self::PATH);
        Log::shouldHaveReceived('warning')->once();
        // url() degrades to the original instead of pointing at a missing variant.
        $this->assertStringEndsWith('/articles/hero.jpg', HeroImages::url(self::PATH, 'thumb'));
    }

    public function test_the_same_big_source_is_processed_when_memory_allows(): void
    {
        if ($this->memoryLimitBytes() !== null && $this->memoryLimitBytes() < 256 * 1048576) {
            $this->markTestSkipped('needs a memory_limit of at least 256M to decode a 20 megapixel photo');
        }
        $this->store($this->bigJpeg());

        HeroImages::process(self::PATH);

        $this->assertSame([1280, 1024], array_slice($this->variant('full'), 0, 2));
        $this->assertSame([480, 384], array_slice($this->variant('thumb'), 0, 2));
    }

    public function test_a_source_beyond_the_hard_dimension_cap_is_refused(): void
    {
        $this->store($this->pngBytes(HeroImages::MAX_SOURCE_SIDE + 1, 8), 'articles/strip.png');
        Log::spy();

        HeroImages::process('articles/strip.png');

        Storage::disk('public')->assertMissing(HeroImages::variantPath('articles/strip.png', 'thumb'));
        Log::shouldHaveReceived('warning')->once();
    }

    public function test_process_never_throws_for_missing_or_broken_files(): void
    {
        Log::spy();
        $this->store('this is not an image', 'articles/fake.jpg');
        $this->store(substr($this->jpegBytes(800, 600), 0, 300), 'articles/truncated.jpg');

        HeroImages::process('articles/missing.jpg');
        HeroImages::process('articles/fake.jpg');
        HeroImages::process('articles/truncated.jpg');

        Log::shouldHaveReceived('warning')->times(3);
        $this->assertSame([], Storage::disk('public')->allFiles('articles/variants'));
    }

    public function test_an_unsupported_image_type_is_left_alone(): void
    {
        $gif = base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7');
        $this->store($gif, 'articles/pixel.jpg');
        Log::spy();

        HeroImages::process('articles/pixel.jpg');

        $this->assertSame([], Storage::disk('public')->allFiles('articles/variants'));
        Log::shouldHaveReceived('warning')->once();
    }

    public function test_no_temporary_files_are_left_behind(): void
    {
        $this->store($this->jpegBytes(900, 600));

        HeroImages::process(self::PATH);

        $this->assertSame([], array_filter(Storage::disk('public')->allFiles(), fn ($f) => str_ends_with($f, '.tmp')));
    }

    public function test_url_prefers_the_variant_and_falls_back_to_the_original(): void
    {
        $this->store($this->jpegBytes(900, 600));

        $this->assertSame('/storage/articles/hero.jpg', HeroImages::url(self::PATH, 'card'));

        HeroImages::process(self::PATH);

        $this->assertSame('/storage/articles/variants/card/hero.jpg.webp', HeroImages::url(self::PATH, 'card'));
        $this->assertSame('/storage/articles/variants/thumb/hero.jpg.webp', HeroImages::url(self::PATH, 'thumb'));
        $this->assertSame('/storage/articles/variants/card/hero.jpg.webp', HeroImages::url(self::PATH));
    }

    public function test_url_is_null_without_an_image(): void
    {
        $this->assertNull(HeroImages::url(null));
        $this->assertNull(HeroImages::url(''));
    }

    public function test_url_percent_encodes_awkward_file_names(): void
    {
        $this->store('x', 'articles/صورة الخبر #1.jpg');

        $this->assertSame(
            '/storage/articles/'.rawurlencode('صورة الخبر #1.jpg'),
            HeroImages::url('articles/صورة الخبر #1.jpg'),
        );
    }

    public function test_url_rejects_an_unknown_size(): void
    {
        $this->expectException(InvalidArgumentException::class);

        HeroImages::url(self::PATH, 'huge');
    }

    /** @return array<string, array{string}> */
    public static function hostilePaths(): array
    {
        return [
            'parent traversal' => ['../../.env'],
            'embedded traversal' => ['articles/../../.env.jpg'],
            'trailing traversal' => ['articles/hero.jpg/..'],
            'absolute unix path' => ['/etc/passwd.jpg'],
            'windows drive' => ['C:\\Windows\\win.jpg'],
            'windows traversal' => ['articles\\..\\..\\x.jpg'],
            'php filter wrapper' => ['php://filter/resource=/etc/passwd.jpg'],
            'phar wrapper' => ['phar://evil.phar/x.jpg'],
            'data wrapper' => ['data:image/png;base64,AAAA.jpg'],
            'file wrapper' => ['file:///etc/passwd.jpg'],
            'null byte' => ["articles/hero.jpg\0.php"],
            'empty segment' => ['articles//hero.jpg'],
            'dot segment' => ['./hero.jpg'],
            'not an image extension' => ['articles/shell.php'],
            'no extension' => ['articles/hero'],
            'too long' => [str_repeat('a', 300).'.jpg'],
        ];
    }

    #[DataProvider('hostilePaths')]
    public function test_hostile_paths_get_no_url_and_are_never_processed(string $path): void
    {
        Log::spy();

        $this->assertNull(HeroImages::safePath($path));
        $this->assertNull(HeroImages::url($path));

        HeroImages::process($path);
        HeroImages::delete($path);

        $this->assertSame([], Storage::disk('public')->allFiles());
        Log::shouldHaveReceived('warning')->once();
    }

    public function test_ordinary_nested_paths_are_accepted(): void
    {
        $this->assertSame('articles/2026/10/abc-DEF_1.jpeg', HeroImages::safePath('articles/2026/10/abc-DEF_1.jpeg'));
        $this->assertSame('hero.WEBP', HeroImages::safePath('hero.WEBP'));
        $this->assertSame('articles/variants/thumb/a.jpg.webp', HeroImages::variantPath('articles/a.jpg', 'thumb'));
        $this->assertSame('variants/thumb/a.jpg.webp', HeroImages::variantPath('a.jpg', 'thumb'));
    }

    public function test_delete_removes_the_original_and_all_variants(): void
    {
        $this->store($this->jpegBytes(900, 600));
        HeroImages::process(self::PATH);
        Storage::disk('public')->put('articles/other.jpg', 'keep me');

        HeroImages::delete(self::PATH);

        $this->assertSame(['articles/other.jpg'], Storage::disk('public')->allFiles());
    }

    /** @return array<string, array{class-string<ImageEngine>}> */
    public static function engines(): array
    {
        return ['gd' => [GdEngine::class], 'imagick' => [ImagickEngine::class]];
    }

    #[DataProvider('engines')]
    public function test_each_engine_renders_upright_webp_variants(string $engineClass): void
    {
        $engine = new $engineClass;
        if (! $engine->available()) {
            $this->markTestSkipped("{$engineClass} is not available with WebP support on this machine");
        }

        $this->store($this->jpegBytes(400, 200, orientation: 6, marker: true));

        $rendered = $engine->render(Storage::disk('public')->path(self::PATH), 400, 200, IMAGETYPE_JPEG, 6, HeroImages::SIZES);

        $this->assertSame(['full', 'card', 'thumb'], array_keys($rendered));
        foreach ($rendered as $bytes) {
            [$width, $height, $image] = $this->decodeWebp($bytes);
            $this->assertSame([200, 400], [$width, $height]);
            $this->assertTrue($this->isRed($image, (int) round($width * 0.88), (int) round($height * 0.12)), 'marker is top-right after a 90 degree clockwise turn');
        }
    }

    private function memoryLimitBytes(): ?int
    {
        $raw = trim((string) ini_get('memory_limit'));
        if ($raw === '' || $raw === '-1') {
            return null;
        }

        return (int) $raw * match (strtolower(substr($raw, -1))) {
            'g' => 1073741824,
            'm' => 1048576,
            'k' => 1024,
            default => 1,
        };
    }
}
