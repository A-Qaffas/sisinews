<?php

namespace App\Filament\Resources\Categories\Tables;

use App\Enums\Locale;
use App\Filament\Resources\Categories\CategoryResource;
use App\Models\Category;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class CategoriesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name_ar')
                    ->label(__('Arabic name'))
                    ->state(fn (Category $record): ?string => $record->translate(Locale::Ar)?->name)
                    ->extraAttributes(['dir' => 'rtl'])
                    ->extraHeaderAttributes(['class' => 'fi-align-end']),
                TextColumn::make('name_en')
                    ->label(__('English name'))
                    ->state(fn (Category $record): ?string => $record->translate(Locale::En)?->name),
                TextColumn::make('sort_order')
                    ->label(__('Position')),
                IconColumn::make('show_in_nav')
                    ->label(__('In navigation'))
                    ->boolean(),
                TextColumn::make('articles_count')
                    ->label(__('Articles')),
            ])
            ->defaultSort('sort_order')
            ->reorderable('sort_order')
            ->recordActions([
                EditAction::make(),
                CategoryResource::deleteAction(),
            ]);
    }
}
