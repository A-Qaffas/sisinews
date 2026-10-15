<?php

namespace App\Filament\Resources\Categories\Schemas;

use App\Enums\Locale;
use App\Filament\Support\Taxonomy\NameFields;
use App\Models\CategoryTranslation;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class CategoryForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make('Names')
                    ->description('Both languages are required: the navigation shows whichever the reader chose.')
                    ->schema([
                        NameFields::make(Locale::Ar, CategoryTranslation::class, required: true),
                        NameFields::make(Locale::En, CategoryTranslation::class, required: true),
                    ]),
                Section::make('Navigation')
                    ->columns(2)
                    ->schema([
                        TextInput::make('sort_order')
                            ->label('Position')
                            ->helperText('Lower numbers come first. You can also drag rows on the list.')
                            ->numeric()
                            ->integer()
                            ->minValue(0)
                            ->maxValue(65535)
                            ->default(0)
                            ->required(),
                        Toggle::make('show_in_nav')
                            ->label('Show in the site navigation')
                            ->default(true),
                    ]),
            ]);
    }
}
