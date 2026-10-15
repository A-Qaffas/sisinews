<?php

namespace App\Filament\Resources\Tags\Schemas;

use App\Enums\Locale;
use App\Filament\Support\Taxonomy\NameFields;
use App\Models\TagTranslation;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class TagForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make('Names')
                    ->description('A tag may exist in one language only; give it at least one name.')
                    ->schema([
                        NameFields::make(Locale::Ar, TagTranslation::class, required: false, alternative: Locale::En),
                        NameFields::make(Locale::En, TagTranslation::class, required: false, alternative: Locale::Ar),
                    ]),
            ]);
    }
}
