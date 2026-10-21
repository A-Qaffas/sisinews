<?php

namespace App\Filament\Widgets;

use App\Enums\Permission;
use App\Enums\TranslationStatus;
use App\Filament\Resources\Articles\ArticleResource;
use App\Filament\Resources\ReviewQueue\ReviewQueueResource;
use App\Filament\Support\StatusPresenter;
use App\Models\ArticleTranslation;
use Carbon\Carbon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Database\Eloquent\Builder;

/** Three numbers for the dashboard. Journalists see their own work, reviewers see the whole newsroom. */
class NewsroomStats extends StatsOverviewWidget
{
    protected ?string $pollingInterval = null;

    protected function getStats(): array
    {
        $user = auth()->user();
        $reviewer = $user->hasPermission(Permission::EditAllStories);

        // Egypt's working week starts on Saturday.
        $weekStart = now(StatusPresenter::TIMEZONE)->startOfWeek(Carbon::SATURDAY)->utc();

        $mine = fn (Builder $query): Builder => $query->whereHas('article', fn (Builder $a) => $a->where('author_id', $user->getKey()));
        $scope = fn (Builder $query): Builder => $reviewer ? $query : $mine($query);

        // Every card leads to the list it counts, so the dashboard is a starting point and not a dead end.
        $articles = ArticleResource::getUrl('index');

        return [
            Stat::make(__('My drafts'), $mine(ArticleTranslation::query())
                ->whereIn('status', [TranslationStatus::Draft->value, TranslationStatus::ChangesRequested->value])
                ->count())
                ->description(__('Drafts, and versions sent back to you'))
                ->url($articles),
            // The same rows as the review queue: versions of a deleted story are not waiting for anyone.
            Stat::make($reviewer ? __('Awaiting review') : __('My versions in review'), ReviewQueueResource::awaitingReview($scope(ArticleTranslation::query()))
                ->count())
                ->description($reviewer ? __('Language versions in the review queue') : __('Waiting for the Editor-in-chief'))
                ->url($reviewer ? ReviewQueueResource::getUrl('index') : $articles),
            Stat::make(__('Published this week'), $scope(ArticleTranslation::query())
                ->where('status', TranslationStatus::Published->value)
                ->where('published_at', '>=', $weekStart)
                ->where('published_at', '<=', now())
                ->count())
                ->description($reviewer ? __('Live language versions since Saturday') : __('Your live versions since Saturday'))
                ->url($articles),
        ];
    }
}
