<?php

namespace Tests\Feature\Seeders;

use Database\Seeders\Support\DemoHeroImage;
use Tests\TestCase;

class DemoHeroImageTest extends TestCase
{
    public function test_it_draws_a_1600_by_900_jpeg_with_gd(): void
    {
        $bytes = DemoHeroImage::jpeg('riverside-promenade', 0);

        $info = getimagesizefromstring($bytes);
        $this->assertSame(1600, $info[0]);
        $this->assertSame(900, $info[1]);
        $this->assertSame(IMAGETYPE_JPEG, $info[2]);
    }

    public function test_the_same_seed_gives_the_same_picture_and_another_seed_a_different_one(): void
    {
        $this->assertSame(DemoHeroImage::jpeg('a-story', 1), DemoHeroImage::jpeg('a-story', 1));
        $this->assertNotSame(DemoHeroImage::jpeg('a-story', 1), DemoHeroImage::jpeg('another-story', 1));
    }

    public function test_every_palette_can_be_drawn_and_an_out_of_range_index_wraps_round(): void
    {
        $this->assertSame(DemoHeroImage::jpeg('wrap', 0), DemoHeroImage::jpeg('wrap', 6));
        $this->assertNotSame(DemoHeroImage::jpeg('wrap', 0), DemoHeroImage::jpeg('wrap', 5));
    }

    public function test_the_current_brand_icon_is_stamped_in_the_corner(): void
    {
        $image = imagecreatefromstring(DemoHeroImage::jpeg('riverside-promenade', 0));

        // Palette 0 is dark navy; the cream disc of public/brand/icon.png sits bottom-right.
        $inBadge = imagecolorsforindex($image, imagecolorat($image, 1600 - 40 - 132 + 16, 900 - 40 - 66));
        $besideBadge = imagecolorsforindex($image, imagecolorat($image, 1600 - 40 - 132 - 24, 900 - 40 - 66));

        $this->assertGreaterThan(220, $inBadge['red']);
        $this->assertLessThan(120, $besideBadge['red']);
    }
}
