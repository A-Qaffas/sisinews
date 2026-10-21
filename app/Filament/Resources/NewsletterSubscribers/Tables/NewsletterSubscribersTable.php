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
                    ->label(__('Email'))
                    ->searchable()
                    ->sortable()
                    ->copyable(),
                TextColumn::make('locale')
                    ->label(__('Language'))
                    ->badge()
                    ->formatStateUsing(fn (Locale $state): string => StatusPresenter::language($state)),
                TextColumn::make('status')
                    ->label(__('Status'))
                    ->badge()
                    ->state(fn (NewsletterSubscriber $record): string => $record->unsubscribed_at === null ? __('Subscribed') : __('Unsubscribed'))
                    // Judged on the record, not on the shown text, which depends on the panel language.
                    ->color(fn (NewsletterSubscriber $record): string => $record->unsubscribed_at === null ? 'success' : 'gray'),
                TextColumn::make('created_at')
                    ->label(__('Signed up'))
                    ->dateTime(fn (): string => StatusPresenter::dateFormat(withYear: true), StatusPresenter::TIMEZONE)
                    ->sortable(),
                TextColumn::make('unsubscribed_at')
                    ->label(__('Unsubscribed'))
                    ->dateTime(fn (): string => StatusPresenter::dateFormat(withYear: true), StatusPresenter::TIMEZONE)
                    ->placeholder('-')
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('locale')
                    ->label(__('Language'))
                    ->options(collect(Locale::cases())->mapWithKeys(fn (Locale $l): array => [$l->value => StatusPresenter::language($l)])->all()),
                TernaryFilter::make('subscribed')
                    ->label(__('Subscription'))
                    ->trueLabel(__('Subscribed'))
                    ->falseLabel(__('Unsubscribed'))
                    ->queries(
                        true: fn (Builder $query): Builder => $query->whereNull('unsubscribed_at'),
                        false: fn (Builder $query): Builder => $query->whereNotNull('unsubscribed_at'),
                        blank: fn (Builder $query): Builder => $query,
                    ),
            ])
            ->recordActions([
                DeleteAction::make()
                    ->modalDescription(__('The address is removed for good. The person can sign up again from the site.')),
            ]);
    }
}
