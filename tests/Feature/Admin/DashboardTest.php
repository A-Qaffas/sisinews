<?php

namespace Tests\Feature\Admin;

use App\Enums\TranslationStatus;
use App\Filament\Widgets\NewsroomStats;
use App\Models\User;
use Livewire\Livewire;

class DashboardTest extends AdminTestCase
{
    /** @return array<string, string> label => value */
    private function stats(User $user): array
    {
        $this->actingAs($user);
        $widget = Livewire::test(NewsroomStats::class)->instance();

        return collect(invade($widget)->getStats())
            ->mapWithKeys(fn ($stat) => [(string) $stat->getLabel() => (string) $stat->getValue()])
            ->all();
    }

    public function test_a_journalist_sees_only_their_own_numbers(): void
    {
        $me = User::factory()->journalist()->create();
        $other = User::factory()->journalist()->create();
        $this->story($me, TranslationStatus::Draft, TranslationStatus::ChangesRequested);
        $this->story($me, TranslationStatus::InReview, TranslationStatus::Published);
        $this->story($other, TranslationStatus::InReview, TranslationStatus::Published);

        $this->assertSame([
            'My drafts' => '2',
            'My versions in review' => '1',
            'Published this week' => '1',
        ], $this->stats($me));
    }

    public function test_a_reviewer_sees_the_whole_newsroom(): void
    {
        $editor = User::factory()->editor()->create();
        $a = User::factory()->journalist()->create();
        $b = User::factory()->journalist()->create();
        $this->story($a, TranslationStatus::InReview, TranslationStatus::InReview);
        $this->story($b, TranslationStatus::InReview, TranslationStatus::Published);
        $this->story($editor, TranslationStatus::Draft, null);

        $this->assertSame([
            'My drafts' => '1',
            'Awaiting review' => '3',
            'Published this week' => '1',
        ], $this->stats($editor));
    }

    public function test_versions_published_before_this_week_are_not_counted(): void
    {
        $editor = User::factory()->editor()->create();
        $article = $this->story($editor, TranslationStatus::Published, null);
        $article->translations->first()->forceFill(['published_at' => now()->subWeeks(2)])->save();

        $this->assertSame('0', $this->stats($editor)['Published this week']);
    }

    public function test_a_scheduled_version_is_not_counted_as_published_yet(): void
    {
        $editor = User::factory()->editor()->create();
        $article = $this->story($editor, TranslationStatus::Published, null);
        $article->translations->first()->forceFill(['published_at' => now()->addDay()])->save();

        $this->assertSame('0', $this->stats($editor)['Published this week']);
    }

    public function test_the_dashboard_page_renders_the_widget(): void
    {
        $this->actingAs(User::factory()->editor()->create())
            ->get('/admin')
            ->assertOk()
            ->assertSeeLivewire(NewsroomStats::class);
    }
}
