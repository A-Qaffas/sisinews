<?php

namespace App\Filament\Resources\NewsletterSubscribers\Tables;

use App\Enums\Locale;
use App\Filament\Support\StatusPresenter;
use App\Models\NewsletterSubscriber;
use Filament\Actions\DeleteAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class NewsletterSubscribersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('email')
                    ->searchable()
                    ->sortable()
                    ->copyable(),
                TextColumn::make('locale')
                    ->label('Language')
                    ->badge()
                    ->formatStateUsing(fn (Locale $state): string => StatusPresenter::language($state)),
                TextColumn::make('status')
                    ->badge()
                    ->state(fn (NewsletterSubscriber $record): string => $record->unsubscribed_at === null ? 'Subscribed' : 'Unsubscribed')
                    ->color(fn (string $state): string => $state === 'Subscribed' ? 'success' : 'gray'),
                TextColumn::make('created_at')
                    ->label('Signed up')
                    ->dateTime('j M Y, H:i', StatusPresenter::TIMEZONE)
                    ->sortable(),
                TextColumn::make('unsubscribed_at')
                    ->label('Unsubscribed')
                    ->dateTime('j M Y, H:i', StatusPresenter::TIMEZONE)
                    ->placeholder('-')
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('locale')
                    ->label('Language')
                    ->options(collect(Locale::cases())->mapWithKeys(fn (Locale $l): array => [$l->value => StatusPresenter::language($l)])->all()),
                TernaryFilter::make('subscribed')
                    ->label('Subscription')
                    ->trueLabel('Subscribed')
                    ->falseLabel('Unsubscribed')
                    ->queries(
                        true: fn (Builder $query): Builder => $query->whereNull('unsubscribed_at'),
                        false: fn (Builder $query): Builder => $query->whereNotNull('unsubscribed_at'),
                        blank: fn (Builder $query): Builder => $query,
                    ),
            ])
            ->recordActions([
                DeleteAction::make()
                    ->modalDescription('The address is removed for good. The person can sign up again from the site.'),
            ]);
    }
}
