<?php

namespace App\Filament\Resources\Pages\Tables;

use App\Enums\Locale;
use App\Filament\Resources\Pages\PageResource;
use App\Filament\Support\PhoneFriendly;
use App\Filament\Support\StatusPresenter;
use App\Models\Page;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class PagesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                // A phone (390 px) fits one title, a compact status and the row buttons; the rest starts at md.
                self::phoneTitleColumn(),
                self::titleColumn(Locale::Ar)->visibleFrom('md'),
                self::titleColumn(Locale::En)->visibleFrom('md'),
                TextColumn::make('slug')
                    ->label(__('Address'))
                    ->searchable()
                    ->extraAttributes(['dir' => 'ltr'])
                    ->visibleFrom('md'),
                self::versionsColumn(),
                IconColumn::make('show_in_footer')
                    ->label(__('In footer'))
                    ->boolean()
                    ->visibleFrom('md'),
                TextColumn::make('sort_order')
                    ->label(__('Position'))
                    ->sortable()
                    ->visibleFrom('md'),
                TextColumn::make('updated_at')
                    ->label(__('Updated'))
                    ->dateTime(fn (): string => StatusPresenter::dateFormat(), StatusPresenter::TIMEZONE)
                    ->sortable()
                    ->visibleFrom('md'),
            ])
            ->defaultSort('sort_order')
            ->recordUrl(fn (Page $record): string => PageResource::getUrl('edit', ['record' => $record]))
            ->recordActions([
                // On a phone the whole row opens the page, so the button is there only for keyboards and desktops.
                PhoneFriendly::hideOnPhone(EditAction::make()),
                // Hidden by PagePolicy for editors and for the five core pages.
                DeleteAction::make()
                    ->modalDescription(__('The page disappears from the site in both languages, and its links stop working. Turn a language off instead to keep the text.')),
            ]);
    }

    /** Phones only: the Arabic title, or the English one when the page has no Arabic version. */
    private static function phoneTitleColumn(): TextColumn
    {
        return TextColumn::make('headline')
            ->label(__('Title'))
            ->state(fn (Page $record): ?string => ($record->translate(Locale::Ar) ?? $record->translate(Locale::En))?->title)
            ->placeholder('-')
            ->weight('bold')
            ->wrap()
            ->lineClamp(3)
            ->grow()
            ->extraAttributes(['dir' => 'auto', 'class' => 'sisi-headline'])
            ->hiddenFrom('md');
    }

    private static function titleColumn(Locale $locale): TextColumn
    {
        return TextColumn::make("{$locale->value}_title")
            ->label($locale === Locale::Ar ? __('Arabic title') : __('English title'))
            ->state(fn (Page $record): ?string => $record->translate($locale)?->title)
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

    /**
     * One badge per language, stacked. The state is "locale:state" because a badge formatter only receives
     * the state: published, hidden (unpublished) or none (no version in that language).
     */
    private static function versionsColumn(): TextColumn
    {
        return TextColumn::make('versions')
            ->label(__('Status'))
            ->badge()
            ->listWithLineBreaks()
            ->state(fn (Page $record): array => array_map(function (Locale $locale) use ($record): string {
                $translation = $record->translate($locale);

                return $locale->value.':'.match ($translation?->is_published) {
                    true => 'published',
                    false => 'hidden',
                    null => 'none',
                };
            }, Locale::cases()))
            ->formatStateUsing(function (string $state): string {
                [$locale, $status] = explode(':', $state, 2);

                return strtoupper($locale).' · '.match ($status) {
                    'published' => __('Published'),
                    'hidden' => __('Hidden'),
                    default => __('No version'),
                };
            })
            ->color(fn (string $state): string => str_ends_with($state, ':published') ? 'success' : 'gray');
    }
}
