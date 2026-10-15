<?php

namespace App\Support\Media;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Throwable;

/**
 * Hero images are stored once on the `public` disk (as uploaded); process() derives WebP
 * variants so pages never ship a 5 MB phone photo. Sizes: thumb (~480w), card (~800w),
 * full (~1280w), never upscaled, written to {dir of original}/variants/{size}/{file}.webp.
 *
 * Limits (the server has a 128 MB memory_limit):
 *  - sources above MAX_SOURCE_SIDE px on a side or MAX_SOURCE_PIXELS pixels are refused outright;
 *  - GD (4 bytes per decoded pixel) also refuses anything that does not fit in the memory that is
 *    still free, which on 128 MB means roughly 12 megapixels. Imagick decodes JPEGs at reduced
 *    scale and is not bound by that.
 * A refused or failed image is logged and simply keeps being served from the original, so the CMS
 * should downscale uploads in the browser (Filament FileUpload image resizing) to stay well inside.
 */
final class HeroImages
{
    public const SIZES = ['thumb' => 480, 'card' => 800, 'full' => 1280];

    public const MAX_SOURCE_SIDE = 8000;

    public const MAX_SOURCE_PIXELS = 40_000_000;

    private const DISK = 'public';

    private const EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp'];

    private const TYPES = [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP];

    /** Generate the size variants for an already-stored image. Idempotent; logs instead of throwing. */
    public static function process(string $storedPath): void
    {
        try {
            self::generate($storedPath);
        } catch (Throwable $e) {
            self::warn('hero image processing failed', $storedPath, $e->getMessage());
        }
    }

    /** Public URL of the requested size, or null when the article has no (valid) image. */
    public static function url(?string $storedPath, string $size = 'card'): ?string
    {
        if (! isset(self::SIZES[$size])) {
            throw new InvalidArgumentException("Unknown hero image size [{$size}].");
        }

        $path = self::safePath($storedPath);
        if ($path === null) {
            return null;
        }

        $disk = Storage::disk(self::DISK);
        $variant = self::variantPath($path, $size);

        return $disk->url(self::encode($disk->exists($variant) ? $variant : $path));
    }

    /** Remove the original and every variant of a stored image. */
    public static function delete(?string $storedPath): void
    {
        $path = self::safePath($storedPath);
        if ($path === null) {
            return;
        }

        $files = [$path];
        foreach (array_keys(self::SIZES) as $size) {
            $files[] = self::variantPath($path, $size);
            $files[] = self::variantPath($path, $size).'.tmp';
        }

        Storage::disk(self::DISK)->delete($files);
    }

    public static function variantPath(string $storedPath, string $size): string
    {
        return dirname($storedPath) === '.'
            ? "variants/{$size}/".basename($storedPath).'.webp'
            : dirname($storedPath)."/variants/{$size}/".basename($storedPath).'.webp';
    }

    /**
     * A stored path is a relative path on the public disk: no traversal, no absolute paths,
     * no stream wrappers (php://, phar://, data:), no control characters, an image extension.
     */
    public static function safePath(?string $path): ?string
    {
        if ($path === null || $path === '' || strlen($path) > 255) {
            return null;
        }
        if (preg_match('/[\x00-\x1F\x7F\\\\:]/', $path) === 1 || str_starts_with($path, '/')) {
            return null;
        }
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                return null;
            }
        }
        if (! in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), self::EXTENSIONS, true)) {
            return null;
        }

        return $path;
    }

    private static function generate(string $storedPath): void
    {
        $path = self::safePath($storedPath);
        if ($path === null) {
            self::warn('refusing an unsafe hero image path', $storedPath);

            return;
        }

        $disk = Storage::disk(self::DISK);
        if (! $disk->exists($path)) {
            self::warn('hero image file is missing', $path);

            return;
        }

        $widths = self::staleWidths($disk, $path);
        if ($widths === []) {
            return;
        }

        $source = $disk->path($path);
        $info = @getimagesize($source);
        if ($info === false || ! in_array($info[2], self::TYPES, true)) {
            self::warn('hero image is not a readable JPEG, PNG or WebP', $path);

            return;
        }

        [$width, $height, $type] = $info;
        if ($width < 1 || $height < 1 || max($width, $height) > self::MAX_SOURCE_SIDE || $width * $height > self::MAX_SOURCE_PIXELS) {
            self::warn("hero image {$width}x{$height} exceeds the processing limit", $path);

            return;
        }

        $orientation = $type === IMAGETYPE_JPEG ? self::orientation($source) : 1;

        $failures = [];
        foreach ([new ImagickEngine, new GdEngine] as $engine) {
            if (! $engine->available()) {
                continue;
            }

            try {
                $rendered = $engine->render($source, $width, $height, $type, $orientation, $widths);
            } catch (Throwable $e) {
                $failures[] = class_basename($engine).': '.$e->getMessage();

                continue;
            }

            foreach ($rendered as $size => $bytes) {
                self::write($disk, self::variantPath($path, $size), $bytes);
            }

            return;
        }

        self::warn('no image engine could create the variants', $path, $failures === [] ? 'neither Imagick nor GD with WebP is available' : implode('; ', $failures));
    }

    /**
     * Variants that are missing or older than the original, as name => width.
     *
     * @return array<string, int>
     */
    private static function staleWidths(Filesystem $disk, string $path): array
    {
        $modified = $disk->lastModified($path);
        $stale = [];
        foreach (self::SIZES as $size => $width) {
            $variant = self::variantPath($path, $size);
            if (! $disk->exists($variant) || $disk->lastModified($variant) < $modified) {
                $stale[$size] = $width;
            }
        }

        return $stale;
    }

    /** Written beside the target and renamed, so a crash never leaves a half-written file that url() would serve. */
    private static function write(Filesystem $disk, string $variant, string $bytes): void
    {
        $disk->put($variant.'.tmp', $bytes);
        $disk->move($variant.'.tmp', $variant);
    }

    private static function orientation(string $source): int
    {
        if (! function_exists('exif_read_data')) {
            return 1;
        }

        $exif = @exif_read_data($source, 'IFD0');
        $orientation = is_array($exif) ? (int) ($exif['Orientation'] ?? 1) : 1;

        return $orientation >= 1 && $orientation <= 8 ? $orientation : 1;
    }

    private static function encode(string $path): string
    {
        return implode('/', array_map('rawurlencode', explode('/', $path)));
    }

    private static function warn(string $message, string $path, string $reason = ''): void
    {
        Log::warning($message, ['path' => $path] + ($reason === '' ? [] : ['reason' => $reason]));
    }
}
