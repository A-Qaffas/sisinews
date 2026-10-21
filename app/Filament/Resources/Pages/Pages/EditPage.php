<?php

namespace App\Filament\Resources\Pages\Pages;

use App\Enums\Locale;
use App\Filament\Resources\Pages\PageResource;
use App\Filament\Resources\Pages\Support\PageWriter;
use App\Models\Page;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Model;

/** @property Page $record */
class EditPage extends EditRecord
{
    protected static string $resource = PageResource::class;

    public function getSubheading(): ?string
    {
        return $this->record->isCore()
            ? __('A core page: it cannot be deleted. Turn off a language to take it off the site.')
            : null;
    }

    protected function getHeaderActions(): array
    {
        return [
            ...array_map(fn (Locale $locale): Action => $this->viewOnSiteAction($locale), Locale::cases()),
            DeleteAction::make()
                ->modalDescription(__('The page disappears from the site in both languages, and its links stop working. Turn a language off instead to keep the text.')),
        ];
    }

    /** Opens the live page in a new tab; offered only for a language that readers can actually reach. */
    private function viewOnSiteAction(Locale $locale): Action
    {
        return Action::make("view_{$locale->value}")
            ->label($locale === Locale::Ar ? __('View Arabic') : __('View English'))
            ->icon(Heroicon::ArrowTopRightOnSquare)
            ->color('gray')
            ->url(fn (): string => $this->record->url($locale), shouldOpenInNewTab: true)
            ->visible(fn (): bool => $this->record->translate($locale)?->is_published === true);
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        return [...$data, ...PageWriter::formState($this->record)];
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        return PageWriter::update($record, $data);
    }
}
