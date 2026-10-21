<?php

namespace App\Filament\Resources\Tags\Pages;

use App\Filament\Resources\Tags\TagResource;
use App\Filament\Support\Taxonomy\TaxonomyTranslations;
use App\Models\Tag;
use App\Models\TagTranslation;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/** @property Tag $record */
class EditTag extends EditRecord
{
    protected static string $resource = TagResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->modalDescription(__('The tag is removed from every article that uses it.')),
        ];
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        return [...$data, ...TaxonomyTranslations::formState($this->record)];
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        return DB::transaction(function () use ($record, $data): Model {
            TaxonomyTranslations::save($record, TagTranslation::class, $data, removeBlank: true);
            $record->touch();

            return $record;
        });
    }
}
