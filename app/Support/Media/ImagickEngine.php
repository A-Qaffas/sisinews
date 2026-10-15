<?php

namespace App\Support\Media;

use Imagick;
use ImagickPixel;
use RuntimeException;

final class ImagickEngine implements ImageEngine
{
    private const QUALITY = 80;

    public function available(): bool
    {
        return extension_loaded('imagick') && Imagick::queryFormats('WEBP') !== [];
    }

    public function render(string $sourcePath, int $width, int $height, int $type, int $orientation, array $widths): array
    {
        // ImageMagick allocates outside PHP's memory_limit: cap it so a shared host's RAM is not
        // exhausted (anything beyond the cap spills to disk).
        Imagick::setResourceLimit(Imagick::RESOURCETYPE_MEMORY, 96 * 1048576);
        Imagick::setResourceLimit(Imagick::RESOURCETYPE_MAP, 192 * 1048576);
        Imagick::setResourceLimit(Imagick::RESOURCETYPE_THREAD, 1);

        $sideways = $orientation >= 5;
        [$uprightWidth, $uprightHeight] = $sideways ? [$height, $width] : [$width, $height];
        arsort($widths);

        $image = new Imagick;
        try {
            if ($type === IMAGETYPE_JPEG) {
                // Lets libjpeg decode at a reduced scale, so a 5000 px photo never sits in memory at full size.
                $hint = max($widths);
                $image->setOption('jpeg:size', $hint.'x'.$hint);
            }
            $image->readImage($sourcePath.'[0]');
            $image->transformImageColorspace(Imagick::COLORSPACE_SRGB);

            $out = [];
            // Largest first, each variant is scaled down from the one before it.
            foreach ($widths as $name => $max) {
                $targetWidth = min($max, $uprightWidth);
                $targetHeight = max(1, (int) round($uprightHeight * $targetWidth / $uprightWidth));
                [$storedWidth, $storedHeight] = $sideways ? [$targetHeight, $targetWidth] : [$targetWidth, $targetHeight];

                $image->resizeImage($storedWidth, $storedHeight, Imagick::FILTER_LANCZOS, 1);

                $frame = clone $image;
                $this->orient($frame, $orientation);
                $frame->setImageOrientation(Imagick::ORIENTATION_TOPLEFT);
                $frame->stripImage();
                $frame->setImageFormat('webp');
                $frame->setImageCompressionQuality(self::QUALITY);
                $bytes = $frame->getImageBlob();
                $frame->clear();

                if ($bytes === '') {
                    throw new RuntimeException('Imagick could not encode WebP.');
                }
                $out[$name] = $bytes;
            }

            return $out;
        } finally {
            $image->clear();
        }
    }

    /** Imagick rotates clockwise; same table as GdEngine::orient(). */
    private function orient(Imagick $image, int $orientation): void
    {
        $none = new ImagickPixel('none');
        $clockwise = [3 => 180, 5 => 90, 6 => 90, 7 => 270, 8 => 270][$orientation] ?? 0;

        if ($clockwise !== 0) {
            $image->rotateImage($none, $clockwise);
        }
        if ($orientation === 4) {
            $image->flipImage();
        }
        if (in_array($orientation, [2, 5, 7], true)) {
            $image->flopImage();
        }
    }
}
