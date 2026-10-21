<?php

namespace Tests\Feature\Site;

use App\Models\Category;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * However many sections the newsroom creates, the header bar shows the first few (panel order) and the rest wait
 * under "More" (المزيد) from md up, as Egyptian news sites do; on phones the menu panel lists every section.
 */
class NavMoreSectionsTest extends TestCase
{
    use RefreshDatabase;

    /** @return list<Category> */
    private function sections(int $count): array
    {
        return collect(range(1, $count))->map(fn (int $i): Category => Category::factory()->create(['sort_order' => $i]))->all();
    }

    private function slug(Category $category): string
    {
        return $category->translations()->where('locale', 'ar')->value('slug');
    }

    public function test_up_to_the_bar_limit_there_is_no_more_menu(): void
    {
        config(['site.nav_sections_in_bar' => 6]);
        $this->sections(6);

        $this->get('/ar')->assertOk()->assertDontSee('data-nav-more', false);
    }

    public function test_sections_beyond_the_limit_go_under_more_and_stay_in_the_phone_menu(): void
    {
        config(['site.nav_sections_in_bar' => 6]);
        $sections = $this->sections(9);

        $html = $this->get('/ar')->assertOk()->getContent();

        $this->assertSame(1, preg_match('#<details data-nav-more>(.*?)</details>#s', $html, $more));
        $this->assertStringContainsString('المزيد', $more[1]);

        foreach ($sections as $i => $section) {
            $href = 'href="'.route('category', ['locale' => 'ar', 'slug' => $this->slug($section)]).'"';

            if ($i < 6) {
                $this->assertStringNotContainsString($href, $more[1], 'section '.($i + 1).' sits in the bar');
            } else {
                $this->assertStringContainsString($href, $more[1], 'section '.($i + 1).' sits under More');
                $this->assertMatchesRegularExpression('#<li class="md:hidden"><a class="nav-link" '.preg_quote($href, '#').'#', $html, 'and in the phone menu');
            }
        }
    }

    public function test_more_is_marked_when_the_reader_is_in_one_of_its_sections(): void
    {
        config(['site.nav_sections_in_bar' => 2]);
        $sections = $this->sections(3);

        $html = $this->get(route('category', ['locale' => 'ar', 'slug' => $this->slug($sections[2])]))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('#<summary class="nav-link nav-more__toggle is-current">#', $html);
    }

    public function test_more_comes_last_after_live(): void
    {
        config(['site.nav_sections_in_bar' => 1]);
        $this->sections(2);

        $this->get('/en')->assertOk()->assertSeeInOrder(['nav-link nav-link--live', 'data-nav-more', 'role="search"'], false);
    }
}
