<?php

namespace Tests\Unit\Media\Concerns;

use GdImage;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

/**
 * Generated image fixtures. The "marker" images are blue with a red block covering the
 * top-left quarter, so a test can tell where the top-left corner ended up after EXIF rotation.
 */
trait MakesImages
{
    /**
     * Storage::fake() uses one fixed folder, which tests running at the same time (other developers,
     * other suites) would share and wipe. This gives the "public" disk a private folder per test.
     */
    protected function fakePublicDisk(): void
    {
        $root = sys_get_temp_dir().'/sisi-news-tests/'.bin2hex(random_bytes(6));
        Storage::set('public', Storage::build(['driver' => 'local', 'root' => $root, 'url' => '/storage']));

        $this->beforeApplicationDestroyed(fn () => File::deleteDirectory($root));
    }

    protected function image(int $width, int $height, bool $marker = false): GdImage
    {
        $image = imagecreatetruecolor($width, $height);
        imagefill($image, 0, 0, imagecolorallocate($image, 0, 0, 255));
        if ($marker) {
            imagefilledrectangle($image, 0, 0, intdiv($width, 2) - 1, intdiv($height, 2) - 1, imagecolorallocate($image, 255, 0, 0));
        } else {
            // A little detail so the encoder has something to compress.
            for ($i = 0; $i < 20; $i++) {
                imagefilledellipse($image, ($i * 97) % $width, ($i * 53) % $height, 120, 90, imagecolorallocate($image, ($i * 40) % 255, 120, 60));
            }
        }

        return $image;
    }

    protected function jpegBytes(int $width, int $height, ?int $orientation = null, bool $marker = false): string
    {
        $image = $this->image($width, $height, $marker);
        ob_start();
        imagejpeg($image, null, 85);
        $bytes = (string) ob_get_clean();
        unset($image);

        return $orientation === null ? $bytes : $this->withExifOrientation($bytes, $orientation);
    }

    protected function pngBytes(int $width, int $height): string
    {
        $image = $this->image($width, $height);
        ob_start();
        imagepng($image);
        $bytes = (string) ob_get_clean();
        unset($image);

        return $bytes;
    }

    /** Inserts a minimal little-endian EXIF block carrying only the Orientation tag. */
    protected function withExifOrientation(string $jpeg, int $orientation): string
    {
        $tiff = 'II'.pack('vV', 42, 8)       // byte order, magic, offset of IFD0
            .pack('v', 1)                    // one entry
            .pack('vvVv', 0x0112, 3, 1, $orientation).pack('v', 0) // Orientation, SHORT, count 1, value + padding
            .pack('V', 0);                   // no IFD1
        $payload = "Exif\0\0".$tiff;
        $segment = "\xFF\xE1".pack('n', strlen($payload) + 2).$payload;

        return substr($jpeg, 0, 2).$segment.substr($jpeg, 2);
    }

    /** @return array{0: int, 1: int, 2: GdImage} width, height, decoded WebP */
    protected function decodeWebp(string $bytes): array
    {
        $info = getimagesizefromstring($bytes);
        $this->assertNotFalse($info, 'variant is not a readable image');
        $this->assertSame(IMAGETYPE_WEBP, $info[2], 'variant is not WebP');

        return [$info[0], $info[1], imagecreatefromstring($bytes)];
    }

    protected function isRed(GdImage $image, int $x, int $y): bool
    {
        $rgb = imagecolorat($image, $x, $y);

        return (($rgb >> 16) & 0xFF) > 180 && ($rgb & 0xFF) < 90;
    }
}
