<?php

namespace App\Filament\Resources\LiveStreams\Tables;

use App\Enums\LiveProvider;
use App\Enums\LiveStatus;
use App\Enums\Locale;
use App\Filament\Resources\LiveStreams\LiveStreamResource;
use App\Filament\Resources\LiveStreams\Schemas\LiveStreamForm;
use App\Filament\Resources\LiveStreams\Support\LiveStreamActions;
use App\Filament\Support\PhoneFriendly;
use App\Filament\Support\StatusPresenter;
use App\Models\LiveStream;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class LiveStreamsTable
{
    /** Filter value for broadcasts that belong to no governorate. */
    private const EGYPT_WIDE = 'egypt';

    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                // A phone (390 px) fits one title, the status badge and the row buttons. Up to xl (a tablet, or a laptop with
                // the sidebar open) the one-title cell stays too: with two title columns and the governorate the Go live / End
                // buttons were pushed off the right edge. From xl each language gets its own column.
                self::phoneTitleColumn(),
                self::titleColumn(Locale::Ar)->visibleFrom('xl'),
                self::titleColumn(Locale::En)->visibleFrom('xl'),
                // English name with the Arabic one beneath it: narrower than both on one line, which a 1280 px laptop needs.
                TextColumn::make('governorate')
                    ->label(__('Governorate'))
                    ->state(fn (LiveStream $record): string => $record->governorate?->name_en ?? __('Egypt-wide'))
                    ->description(fn (LiveStream $record): ?string => $record->governorate?->name_ar)
                    ->visibleFrom('xl'),
                TextColumn::make('provider')
                    ->label(__('Provider'))
                    ->badge()
                    ->formatStateUsing(fn (LiveProvider $state): string => $state->label())
                    ->color('gray')
                    // Rarely decisive, and the row actions need the room on a 1280 px laptop with the sidebar open.
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->visibleFrom('md'),
                self::statusColumn(),
                TextColumn::make('when')
                    ->label(__('When'))
                    ->state(fn (LiveStream $record): ?string => self::when($record))
                    ->placeholder('-')
                    ->visibleFrom('md'),
            ])
            // Live first (that is what an editor came to manage), then the ones still to come, then the past. Within each group:
            // the most recent start, the soonest scheduled time (unscheduled last), the most recent end. Plain CASE and IS NULL
            // only, so MySQL, MariaDB and SQLite sort alike.
            ->defaultSort(fn (Builder $query): Builder => $query
                ->orderByRaw("case status when 'live' then 0 when 'scheduled' then 1 else 2 end")
                ->orderByRaw("case when status = 'live' then started_at end desc")
                ->orderByRaw("case when status = 'scheduled' and scheduled_at is null then 1 else 0 end")
                ->orderByRaw("case when status = 'scheduled' then scheduled_at end asc")
                ->orderByRaw("case when status = 'ended' then ended_at end desc")
                ->orderByDesc('id'))
            ->filters([
                SelectFilter::make('status')
                    ->label(__('Status'))
                    ->options(collect(LiveStatus::cases())->mapWithKeys(fn (LiveStatus $status): array => [$status->value => $status->label()])->all()),
                SelectFilter::make('governorate_id')
                    ->label(__('Governorate'))
                    ->options(fn (): array => [self::EGYPT_WIDE => __('Egypt-wide'), ...LiveStreamForm::governorateOptions()])
                    ->query(fn (Builder $query, array $data): Builder => match (true) {
                        blank($data['value'] ?? null) => $query,
                        $data['value'] === self::EGYPT_WIDE => $query->whereNull('governorate_id'),
                        default => $query->where('governorate_id', $data['value']),
                    }),
            ])
            ->recordUrl(fn (LiveStream $record): string => LiveStreamResource::getUrl('edit', ['record' => $record]))
            ->recordActions([
                // Only one of these two shows on a row, and below md it shrinks to its icon so it fits beside the title.
                PhoneFriendly::iconOnPhone(LiveStreamActions::goLive()),
                PhoneFriendly::iconOnPhone(LiveStreamActions::end()),
                // On a phone the whole row opens the broadcast, so the button is there only for keyboards and desktops.
                PhoneFriendly::hideOnPhone(EditAction::make()),
                // The rare, destructive move sits behind one icon. Hidden by the policy while a broadcast is live.
                ActionGroup::make([
                    DeleteAction::make()
                        ->modalDescription(__('The broadcast and its titles are removed from the dashboard and the site. The video itself stays on YouTube or Vimeo.')),
                ]),
            ])
            ->emptyStateHeading(__('No broadcasts yet'))
            ->emptyStateDescription(__('Register a YouTube or Vimeo broadcast, then press Go live when it starts.'))
            ->emptyStateIcon(Heroicon::OutlinedSignal);
    }

    /** Below xl: the Arabic title, or the English one when there is no Arabic version, with the governorate under it. */
    private static function phoneTitleColumn(): TextColumn
    {
        return TextColumn::make('headline')
            ->label(__('Title'))
            ->state(fn (LiveStream $record): ?string => ($record->translation(Locale::Ar) ?? $record->translation(Locale::En))?->title)
            ->description(fn (LiveStream $record): string => $record->governorate?->name_ar ?? __('Egypt-wide'))
            ->placeholder('-')
            ->weight('bold')
            ->wrap()
            ->lineClamp(3)
            ->grow()
            ->extraAttributes(['dir' => 'auto', 'class' => 'sisi-headline'])
            ->hiddenFrom('xl');
    }

    private static function titleColumn(Locale $locale): TextColumn
    {
        return TextColumn::make("{$locale->value}_title")
            ->label($locale === Locale::Ar ? __('Arabic title') : __('English title'))
            ->state(fn (LiveStream $record): ?string => $record->translation($locale)?->title)
            ->placeholder('-')
            ->wrap()
            ->lineClamp(2)
            ->extraAttributes(['dir' => $locale->dir(), 'class' => 'sisi-headline'])
            ->extraHeaderAttributes($locale === Locale::Ar ? ['class' => 'fi-align-end'] : [])
            ->searchable(query: fn (Builder $query, string $search): Builder => $query->whereHas(
                'translations',
                fn (Builder $t) => $t->where('locale', $locale->value)
                    ->where('title', 'like', '%'.addcslashes($search, '%_\\').'%'),
            ));
    }

    /** "Live now" is the loud one: crimson with the signal icon and bold type. Scheduled reuses the panel's violet "not on air yet". */
    private static function statusColumn(): TextColumn
    {
        return TextColumn::make('status')
            ->label(__('Status'))
            ->badge()
            ->weight(FontWeight::Bold)
            ->extraAttributes(['class' => 'sisi-live-status'])
            ->formatStateUsing(fn (LiveStatus $state): string => $state->label())
            ->color(fn (LiveStatus $state): string => match ($state) {
                LiveStatus::Live => 'danger',
                LiveStatus::Scheduled => StatusPresenter::SCHEDULED_COLOR,
                LiveStatus::Ended => 'gray',
            })
            ->icon(fn (LiveStatus $state): ?Heroicon => $state === LiveStatus::Live ? Heroicon::Signal : null);
    }

    private static function when(LiveStream $record): ?string
    {
        return match ($record->status) {
            LiveStatus::Live => $record->started_at === null ? null : __('Since :time', ['time' => StatusPresenter::when($record->started_at)]),
            LiveStatus::Ended => $record->ended_at === null ? null : __('Ended :time', ['time' => StatusPresenter::when($record->ended_at)]),
            LiveStatus::Scheduled => $record->scheduled_at === null ? null : __('Due :time', ['time' => StatusPresenter::when($record->scheduled_at)]),
        };
    }
}
