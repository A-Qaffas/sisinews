<?php

namespace App\Filament\Resources\Categories\Pages;

use App\Filament\Resources\Categories\CategoryResource;
use App\Filament\Support\Taxonomy\TaxonomyTranslations;
use App\Models\Category;
use App\Models\CategoryTranslation;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class CreateCategory extends CreateRecord
{
    protected static string $resource = CategoryResource::class;

    protected static bool $canCreateAnother = false;

    protected function handleRecordCreation(array $data): Model
    {
        return DB::transaction(function () use ($data): Category {
            $category = Category::query()->create(Arr::only($data, ['sort_order', 'show_in_nav']));
            TaxonomyTranslations::save($category, CategoryTranslation::class, $data, removeBlank: false);

            return $category;
        });
    }
}
