<?php

namespace App\Filament\Resources\Tags\Pages;

use App\Filament\Resources\Tags\TagResource;
use App\Filament\Support\Taxonomy\TaxonomyTranslations;
use App\Models\Tag;
use App\Models\TagTranslation;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class CreateTag extends CreateRecord
{
    protected static string $resource = TagResource::class;

    protected static bool $canCreateAnother = false;

    protected function handleRecordCreation(array $data): Model
    {
        return DB::transaction(function () use ($data): Tag {
            $tag = Tag::query()->create();
            TaxonomyTranslations::save($tag, TagTranslation::class, $data, removeBlank: true);

            return $tag;
        });
    }
}
