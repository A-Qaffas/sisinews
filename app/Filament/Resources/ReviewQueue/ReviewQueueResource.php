<?php

namespace App\Filament\Resources\ReviewQueue;

use App\Enums\Permission;
use App\Enums\TranslationStatus;
use App\Filament\Resources\ReviewQueue\Pages\ListReviewQueue;
use App\Filament\Resources\ReviewQueue\Tables\ReviewQueueTable;
use App\Filament\Support\AdminNavigationGroup;
use App\Models\ArticleTranslation;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/** Language versions waiting for an editor, oldest first. Reviewers (editor/admin) only. */
class ReviewQueueResource extends Resource
{
    protected static ?string $model = ArticleTranslation::class;

    protected static ?string $slug = 'review-queue';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentCheck;

    protected static string|\UnitEnum|null $navigationGroup = AdminNavigationGroup::Newsroom;

    protected static ?int $navigationSort = 2;

    public static function getNavigationLabel(): string
    {
        return __('Review queue');
    }

    public static function getModelLabel(): string
    {
        return __('version awaiting review');
    }

    public static function getPluralModelLabel(): string
    {
        return __('review queue');
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->hasPermission(Permission::PublishStories) ?? false;
    }

    public static function getNavigationBadge(): ?string
    {
        $waiting = static::awaitingReview(ArticleTranslation::query())->count();

        return $waiting > 0 ? (string) $waiting : null;
    }

    public static function getNavigationBadgeColor(): string
    {
        return 'warning';
    }

    public static function getEloquentQuery(): Builder
    {
        return static::awaitingReview(parent::getEloquentQuery())
            ->with(['article.author', 'article.category.translations'])
            // The last status change of a version that is in review is the moment it was submitted.
            ->withMax('statusChanges as submitted_at', 'created_at');
    }

    /**
     * Versions in review whose story still exists. A deleted (soft-deleted) story keeps its rows, and its versions must
     * not linger in the queue, the menu badge or the dashboard count: the queue would have no story to show for them.
     */
    public static function awaitingReview(Builder $query): Builder
    {
        return $query
            ->where('status', TranslationStatus::InReview->value)
            // whereHas applies the SoftDeletes scope of Article, so a deleted story drops out.
            ->whereHas('article');
    }

    public static function table(Table $table): Table
    {
        return ReviewQueueTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListReviewQueue::route('/'),
        ];
    }
}
