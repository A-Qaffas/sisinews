<?php

namespace App\Support\Media;

/** One way of turning a stored photo into WebP variants (Imagick or GD). */
interface ImageEngine
{
    public function available(): bool;

    /**
     * @param  int  $type  IMAGETYPE_JPEG, IMAGETYPE_PNG or IMAGETYPE_WEBP
     * @param  int  $orientation  EXIF orientation 1-8
     * @param  non-empty-array<string, int>  $widths  variant name => maximum width in px of the upright image
     * @return array<string, string> variant name => WebP bytes, never wider than the source
     *
     * @throws \Throwable when the image cannot be decoded or does not fit in memory
     */
    public function render(string $sourcePath, int $width, int $height, int $type, int $orientation, array $widths): array;
}
