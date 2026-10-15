<?php

namespace App\Filament\Resources\Articles;

use App\Editorial\EditorialWorkflow;
use App\Enums\TranslationStatus;
use App\Filament\Resources\Articles\Pages\CreateArticle;
use App\Filament\Resources\Articles\Pages\EditArticle;
use App\Filament\Resources\Articles\Pages\ListArticles;
use App\Filament\Resources\Articles\Schemas\ArticleForm;
use App\Filament\Resources\Articles\Tables\ArticlesTable;
use App\Models\Article;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;

class ArticleResource extends Resource
{
    protected static ?string $model = Article::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedNewspaper;

    protected static string|\UnitEnum|null $navigationGroup = 'Newsroom';

    protected static ?int $navigationSort = 1;

    protected static ?string $modelLabel = 'article';

    public static function form(Schema $schema): Schema
    {
        return ArticleForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ArticlesTable::configure($table);
    }

    /** Journalists only ever see their own stories; reviewers see all of them. */
    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()->with(['translations', 'category.translations', 'author']);
        $user = auth()->user();

        return $user->isReviewer() ? $query : $query->where('author_id', $user->getKey());
    }

    /**
     * Opening a story is not the same as editing it: an author must still be able to open
     * a story that is in review to read the reviewer's note. The form disables whatever
     * the policies do not allow, and ArticleWriter enforces the same rules on save.
     */
    public static function getEditAuthorizationResponse(Model $record): Response
    {
        return Gate::inspect('view', $record);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListArticles::route('/'),
            'create' => CreateArticle::route('/create'),
            'edit' => EditArticle::route('/{record}/edit'),
        ];
    }

    /** The note left on a version that was waiting for review when its story was deleted. */
    public const DELETED_WHILE_IN_REVIEW_NOTE = 'The story was deleted while this version was waiting for review.';

    /**
     * Soft-deleting must not leave the story readable: its live versions are archived
     * (audited, through the workflow) first, because public queries read the translation rows.
     * A version waiting for review is sent back with a note, so nothing is left waiting on a
     * story that no longer exists (and the audit trail says why, should it be restored).
     */
    public static function deleteAction(): DeleteAction
    {
        return DeleteAction::make()
            ->modalDescription('Live versions are taken off the site first. An administrator can restore the article later.')
            ->before(function (Article $record): void {
                $workflow = app(EditorialWorkflow::class);

                $record->translations()
                    ->whereIn('status', [TranslationStatus::Published->value, TranslationStatus::InReview->value])
                    ->get()
                    ->each(function ($translation) use ($workflow, $record): void {
                        $translation->setRelation('article', $record);
                        $translation->status === TranslationStatus::Published
                            ? $workflow->archive($translation, auth()->user())
                            : $workflow->requestChanges($translation, auth()->user(), self::DELETED_WHILE_IN_REVIEW_NOTE);
                    });
            });
    }
}
