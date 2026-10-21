<?php

namespace App\Filament\Resources\Articles\Pages;

use App\Enums\Locale;
use App\Filament\Resources\Articles\ArticleResource;
use App\Filament\Resources\Articles\Pages\Concerns\HasWorkflowActions;
use App\Filament\Resources\Articles\Support\ArticleAccess;
use App\Filament\Resources\Articles\Support\ArticleWriter;
use App\Models\Article;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

/** @property Article $record */
class EditArticle extends EditRecord
{
    use HasWorkflowActions;

    protected static string $resource = ArticleResource::class;

    /** A story the person may only read (in review, published, or in a section no longer theirs) does not say "Edit". */
    public function getTitle(): string
    {
        return ArticleAccess::canEditAnything(auth()->user(), $this->record) ? parent::getTitle() : __('View article');
    }

    protected function getHeaderActions(): array
    {
        return [
            ...$this->workflowActions(),
            // Outlined, so the rarest and most destructive move does not look like the sibling Unpublish button.
            ArticleResource::deleteAction()->outlined(),
        ];
    }

    /** The article's own columns plus one nested state per language and the tag ids. */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        foreach (Locale::cases() as $locale) {
            $data[$locale->value] = $this->record->translation($locale)
                ?->only(['title', 'slug', 'excerpt', 'body', 'hero_caption', 'meta_description']) ?? [];
        }

        $data['tags'] = $this->record->tags()->pluck('tags.id')->all();

        return $data;
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        return app(ArticleWriter::class)->update($record, $data, auth()->user());
    }

    public function save(bool $shouldRedirect = true, bool $shouldSendSavedNotification = true): void
    {
        abort_unless(ArticleAccess::canEditAnything(auth()->user(), $this->record), 403);

        parent::save($shouldRedirect, $shouldSendSavedNotification);
    }

    /** An author looking at a story that is in review has nothing to save. */
    protected function getFormActions(): array
    {
        return ArticleAccess::canEditAnything(auth()->user(), $this->record)
            ? parent::getFormActions()
            : [$this->getCancelFormAction()];
    }

    protected function getSavedNotificationTitle(): ?string
    {
        return __('Article saved');
    }
}
