<?php

namespace App\Support\Media;

use GdImage;
use RuntimeException;

final class GdEngine implements ImageEngine
{
    private const QUALITY = 80;

    /** Headroom for libwebp's encoder buffers and the rest of the request. */
    private const OVERHEAD_BYTES = 6 * 1048576;

    public function available(): bool
    {
        return extension_loaded('gd') && function_exists('imagewebp');
    }

    public function render(string $sourcePath, int $width, int $height, int $type, int $orientation, array $widths): array
    {
        $sideways = $orientation >= 5;
        [$uprightWidth, $uprightHeight] = $sideways ? [$height, $width] : [$width, $height];

        arsort($widths);
        $targets = [];
        foreach ($widths as $name => $max) {
            $targetWidth = min($max, $uprightWidth);
            $targets[$name] = [$targetWidth, max(1, (int) round($uprightHeight * $targetWidth / $uprightWidth))];
        }

        [$largestWidth, $largestHeight] = reset($targets);
        $this->assertFitsInMemory($width, $height, $largestWidth, $largestHeight);

        $source = match ($type) {
            IMAGETYPE_JPEG => imagecreatefromjpeg($sourcePath),
            IMAGETYPE_PNG => imagecreatefrompng($sourcePath),
            IMAGETYPE_WEBP => imagecreatefromwebp($sourcePath),
            default => false,
        };
        if (! $source instanceof GdImage) {
            throw new RuntimeException('GD could not decode the image.');
        }

        // Largest variant first: it is cut from the full-size decode (which is then freed),
        // and every smaller one is scaled down from the variant before it.
        $base = null;
        $out = [];
        foreach ($targets as $name => [$targetWidth, $targetHeight]) {
            if ($base === null) {
                [$storedWidth, $storedHeight] = $sideways ? [$targetHeight, $targetWidth] : [$targetWidth, $targetHeight];
                $base = $this->orient($this->resample($source, $storedWidth, $storedHeight), $orientation);
                $source = null;
            } elseif (imagesx($base) !== $targetWidth) {
                $base = $this->resample($base, $targetWidth, $targetHeight);
            }

            $out[$name] = $this->encode($base);
        }

        return $out;
    }

    /**
     * GD decodes at 4 bytes per pixel inside PHP's own memory_limit, and breaching it is a
     * fatal error no try/catch can handle, so an image that cannot fit is refused up front.
     */
    private function assertFitsInMemory(int $width, int $height, int $largestWidth, int $largestHeight): void
    {
        $limit = $this->memoryLimit();
        if ($limit === null) {
            return;
        }

        // Decoded source + resampled canvas + the rotated copy of that canvas.
        $needed = ($width * $height + 2 * $largestWidth * $largestHeight) * 4 + self::OVERHEAD_BYTES;
        $available = $limit - memory_get_usage(true);

        if ($needed > $available) {
            throw new RuntimeException(sprintf(
                'a %dx%d image needs about %d MB and only %d MB of memory_limit is free',
                $width,
                $height,
                intdiv($needed, 1048576),
                max(0, intdiv($available, 1048576)),
            ));
        }
    }

    private function memoryLimit(): ?int
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

    private function resample(GdImage $from, int $width, int $height): GdImage
    {
        $canvas = imagecreatetruecolor($width, $height);
        imagealphablending($canvas, false);
        imagesavealpha($canvas, true);
        imagefill($canvas, 0, 0, imagecolorallocatealpha($canvas, 0, 0, 0, 127));
        imagecopyresampled($canvas, $from, 0, 0, 0, 0, $width, $height, imagesx($from), imagesy($from));

        return $canvas;
    }

    /** GD rotates counter-clockwise; EXIF says how the stored pixels must be turned to display upright. */
    private function orient(GdImage $image, int $orientation): GdImage
    {
        return match ($orientation) {
            2 => $this->flip($image, IMG_FLIP_HORIZONTAL),
            3 => $this->rotate($image, 180),
            4 => $this->flip($image, IMG_FLIP_VERTICAL),
            5 => $this->flip($this->rotate($image, 270), IMG_FLIP_HORIZONTAL),
            6 => $this->rotate($image, 270),
            7 => $this->flip($this->rotate($image, 90), IMG_FLIP_HORIZONTAL),
            8 => $this->rotate($image, 90),
            default => $image,
        };
    }

    private function rotate(GdImage $image, int $degrees): GdImage
    {
        $rotated = imagerotate($image, $degrees, imagecolorallocatealpha($image, 0, 0, 0, 127));
        if (! $rotated instanceof GdImage) {
            throw new RuntimeException('GD could not rotate the image.');
        }
        imagealphablending($rotated, false);
        imagesavealpha($rotated, true);

        return $rotated;
    }

    private function flip(GdImage $image, int $mode): GdImage
    {
        imageflip($image, $mode);

        return $image;
    }

    private function encode(GdImage $image): string
    {
        ob_start();
        $written = imagewebp($image, null, self::QUALITY);
        $bytes = (string) ob_get_clean();

        if (! $written || $bytes === '') {
            throw new RuntimeException('GD could not encode WebP.');
        }

        return $bytes;
    }
}
