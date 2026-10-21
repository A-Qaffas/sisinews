<?php

namespace App\Filament\Resources\Tags\Tables;

use App\Enums\Locale;
use App\Models\Tag;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class TagsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name_ar')
                    ->label(__('Arabic name'))
                    ->state(fn (Tag $record): ?string => $record->translations->firstWhere('locale', Locale::Ar)?->name)
                    ->placeholder('-')
                    ->extraAttributes(['dir' => 'rtl'])
                    ->extraHeaderAttributes(['class' => 'fi-align-end'])
                    ->searchable(query: fn (Builder $query, string $search): Builder => self::searchNames($query, $search)),
                TextColumn::make('name_en')
                    ->label(__('English name'))
                    ->state(fn (Tag $record): ?string => $record->translations->firstWhere('locale', Locale::En)?->name)
                    ->placeholder('-')
                    ->searchable(query: fn (Builder $query, string $search): Builder => self::searchNames($query, $search)),
                TextColumn::make('articles_count')
                    ->label(__('Articles'))
                    ->sortable(),
            ])
            ->defaultSort('id', 'desc')
            ->recordActions([
                EditAction::make(),
                DeleteAction::make()
                    ->modalDescription(__('The tag is removed from every article that uses it.')),
            ]);
    }

    private static function searchNames(Builder $query, string $search): Builder
    {
        return $query->whereHas(
            'translations',
            fn (Builder $t) => $t->where('name', 'like', '%'.addcslashes($search, '%_\\').'%'),
        );
    }
}
