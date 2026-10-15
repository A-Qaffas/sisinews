<?php

namespace App\Filament\Resources\Categories\Pages;

use App\Filament\Resources\Categories\CategoryResource;
use App\Filament\Support\Taxonomy\TaxonomyTranslations;
use App\Models\Category;
use App\Models\CategoryTranslation;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/** @property Category $record */
class EditCategory extends EditRecord
{
    protected static string $resource = CategoryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CategoryResource::deleteAction(),
        ];
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        return [...$data, ...TaxonomyTranslations::formState($this->record)];
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        return DB::transaction(function () use ($record, $data): Model {
            $record->update(Arr::only($data, ['sort_order', 'show_in_nav']));
            TaxonomyTranslations::save($record, CategoryTranslation::class, $data, removeBlank: false);

            return $record;
        });
    }
}
