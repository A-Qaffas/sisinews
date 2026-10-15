<?php

namespace Database\Seeders\Support;

use Random\Engine\Mt19937;
use Random\Randomizer;
use RuntimeException;

/**
 * Draws the placeholder hero pictures for demo stories with GD alone (no downloads,
 * no font files): a brand-colour gradient, a moon disc with its ring and layered river
 * ribbons, echoing the logo, with the CURRENT public/brand/icon.png stamped in the
 * corner. No headline text is drawn because GD cannot shape Arabic letters.
 *
 * The same seed always yields the same picture.
 */
final class DemoHeroImage
{
    public const WIDTH = 1600;

    public const HEIGHT = 900;

    /** Shapes are drawn at this multiple and scaled down, which anti-aliases their edges. */
    private const SCALE = 2;

    private const ICON_SIZE = 132;

    private const ICON_MARGIN = 40;

    private const JPEG_QUALITY = 85;

    /** One look per site section. Every colour is a brand colour or a tint of one. */
    private const PALETTES = [
        ['top' => '#162939', 'bottom' => '#2B4660', 'moon' => '#F7F2E9', 'ribbons' => ['#F7F2E9', '#9B2C2C', '#0E1D2A']],
        ['top' => '#162939', 'bottom' => '#7A2323', 'moon' => '#F7F2E9', 'ribbons' => ['#9B2C2C', '#F7F2E9', '#162939']],
        ['top' => '#F7F2E9', 'bottom' => '#E3D5BB', 'moon' => '#9B2C2C', 'ribbons' => ['#162939', '#2B4660', '#9B2C2C']],
        ['top' => '#9B2C2C', 'bottom' => '#4A1620', 'moon' => '#F7F2E9', 'ribbons' => ['#162939', '#F7F2E9', '#6E1F1F']],
        ['top' => '#1F3A52', 'bottom' => '#0E1D2A', 'moon' => '#E3D5BB', 'ribbons' => ['#3A5A78', '#F7F2E9', '#9B2C2C']],
        ['top' => '#FBF7EF', 'bottom' => '#E8CFC4', 'moon' => '#162939', 'ribbons' => ['#9B2C2C', '#162939', '#D9B8A8']],
    ];

    /** @return string JPEG bytes, WIDTH x HEIGHT */
    public static function jpeg(string $seed, int $palette): string
    {
        $look = self::PALETTES[$palette % count(self::PALETTES)];
        $rng = new Randomizer(new Mt19937(crc32($seed)));
        $w = self::WIDTH * self::SCALE;
        $h = self::HEIGHT * self::SCALE;

        $canvas = imagecreatetruecolor($w, $h);
        self::gradient($canvas, $w, $h, $look['top'], $look['bottom']);
        self::moon($canvas, $w, $h, $look['moon'], $rng);
        foreach ($look['ribbons'] as $i => $colour) {
            self::ribbon($canvas, $w, $h, $colour, $i, $rng);
        }

        $image = imagescale($canvas, self::WIDTH, self::HEIGHT, IMG_BICUBIC);
        self::stampBrandIcon($image);

        ob_start();
        imagejpeg($image, null, self::JPEG_QUALITY);

        return (string) ob_get_clean();
    }

    private static function gradient(\GdImage $img, int $w, int $h, string $top, string $bottom): void
    {
        [$r1, $g1, $b1] = self::rgb($top);
        [$r2, $g2, $b2] = self::rgb($bottom);

        for ($y = 0; $y < $h; $y++) {
            $t = $y / ($h - 1);
            $colour = imagecolorallocate(
                $img,
                (int) round($r1 + ($r2 - $r1) * $t),
                (int) round($g1 + ($g2 - $g1) * $t),
                (int) round($b1 + ($b2 - $b1) * $t),
            );
            imageline($img, 0, $y, $w, $y, $colour);
        }
    }

    private static function moon(\GdImage $img, int $w, int $h, string $hex, Randomizer $rng): void
    {
        $cx = (int) ($w * $rng->getInt(18, 82) / 100);
        $cy = (int) ($h * $rng->getInt(31, 42) / 100);
        $diameter = (int) ($h * $rng->getInt(30, 44) / 100);

        imagefilledellipse($img, $cx, $cy, $diameter, $diameter, self::colour($img, $hex, 70));

        // The open ring around the disc mirrors the arc in the logo mark. It is a polygon
        // strip, not imagearc(): GD's thick arcs come out notched.
        $outer = $diameter * 0.61 + 3 * self::SCALE;
        $inner = $diameter * 0.61 - 3 * self::SCALE;
        $points = [];
        for ($deg = 205; $deg <= 345; $deg += 2) {
            $points[] = (int) ($cx + $outer * cos(deg2rad($deg)));
            $points[] = (int) ($cy + $outer * sin(deg2rad($deg)));
        }
        for ($deg = 345; $deg >= 205; $deg -= 2) {
            $points[] = (int) ($cx + $inner * cos(deg2rad($deg)));
            $points[] = (int) ($cy + $inner * sin(deg2rad($deg)));
        }
        imagefilledpolygon($img, $points, self::colour($img, $hex, 25));
    }

    /** A pointed, tapering band that sweeps across the picture like a stroke of the river. */
    private static function ribbon(\GdImage $img, int $w, int $h, string $hex, int $layer, Randomizer $rng): void
    {
        $base = $h * (0.55 + 0.13 * $layer) + $rng->getInt(-40, 40) * self::SCALE;
        $amplitude = $h * $rng->getInt(5, 9) / 100;
        $wavelength = $w * $rng->getInt(55, 90) / 100;
        $phase = $rng->getInt(0, 628) / 100;
        $thickness = $h * (0.10 + 0.04 * (2 - $layer));
        $step = 8 * self::SCALE;

        $upper = [];
        $lower = [];
        for ($x = 0; $x <= $w; $x += $step) {
            $y = $base + $amplitude * sin(2 * M_PI * $x / $wavelength + $phase);
            $taper = sin(M_PI * $x / $w);
            $upper[] = [$x, $y - $thickness * $taper / 2];
            $lower[] = [$x, $y + $thickness * $taper / 2];
        }

        $points = [];
        foreach (array_merge($upper, array_reverse($lower)) as [$px, $py]) {
            $points[] = (int) $px;
            $points[] = (int) $py;
        }
        imagefilledpolygon($img, $points, self::colour($img, $hex, 18 + 14 * $layer));
    }

    private static function stampBrandIcon(\GdImage $image): void
    {
        $icon = @imagecreatefrompng(public_path('brand/icon.png'));
        if ($icon === false) {
            throw new RuntimeException('public/brand/icon.png is missing or unreadable; demo hero images are stamped with the current brand icon.');
        }

        imagecopyresampled(
            $image, $icon,
            self::WIDTH - self::ICON_SIZE - self::ICON_MARGIN, self::HEIGHT - self::ICON_SIZE - self::ICON_MARGIN, 0, 0,
            self::ICON_SIZE, self::ICON_SIZE, imagesx($icon), imagesy($icon),
        );
    }

    /** @param int<0,127> $alpha GD alpha: 0 opaque, 127 fully transparent */
    private static function colour(\GdImage $img, string $hex, int $alpha): int
    {
        [$r, $g, $b] = self::rgb($hex);

        return imagecolorallocatealpha($img, $r, $g, $b, $alpha);
    }

    /** @return array{int, int, int} */
    private static function rgb(string $hex): array
    {
        return [hexdec(substr($hex, 1, 2)), hexdec(substr($hex, 3, 2)), hexdec(substr($hex, 5, 2))];
    }
}
