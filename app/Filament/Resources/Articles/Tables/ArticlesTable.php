<?php

namespace App\Filament\Resources\Articles\Tables;

use App\Enums\Locale;
use App\Enums\TranslationStatus;
use App\Filament\Resources\Articles\ArticleResource;
use App\Filament\Support\PhoneFriendly;
use App\Filament\Support\StatusPresenter;
use App\Filament\Support\TaxonomyLabels;
use App\Models\Article;
use App\Models\User;
use App\Support\Media\HeroImages;
use Carbon\CarbonImmutable;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreAction;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ArticlesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                // A phone (390 px) fits one headline, a compact status and the row button; every
                // other column starts at md, so nothing needs horizontal scrolling.
                ImageColumn::make('hero_image')
                    ->label('')
                    ->state(fn (Article $record): ?string => HeroImages::url($record->hero_image, 'thumb'))
                    ->imageSize(40)
                    ->square()
                    ->visibleFrom('md'),
                self::phoneHeadlineColumn(),
                self::titleColumn(Locale::Ar)->visibleFrom('md'),
                self::titleColumn(Locale::En)->visibleFrom('md'),
                // Journalists see it by default: a story in a section that is no longer theirs says so here.
                TextColumn::make('category')
                    ->label(__('Category'))
                    ->state(fn (Article $record): string => $record->category->nameIn(Locale::current()))
                    ->description(fn (Article $record): ?string => self::notYourSection($record))
                    ->toggleable(isToggledHiddenByDefault: fn (): bool => auth()->user()->isReviewer())
                    ->visibleFrom('md'),
                TextColumn::make('author.name')
                    ->label(__('Author'))
                    ->toggleable()
                    ->visible(fn (): bool => auth()->user()->isReviewer())
                    ->visibleFrom('md'),
                self::versionsColumn(),
                IconColumn::make('is_breaking')
                    ->label(__('Breaking'))
                    ->boolean()
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->visibleFrom('md'),
                TextColumn::make('updated_at')
                    ->label(__('Updated'))
                    ->dateTime(fn (): string => StatusPresenter::dateFormat(), StatusPresenter::TIMEZONE)
                    ->sortable()
                    ->visibleFrom('md'),
            ])
            ->defaultSort('updated_at', 'desc')
            ->filters([
                SelectFilter::make('status')
                    ->label(__('Status (any language)'))
                    ->options(collect(TranslationStatus::cases())->mapWithKeys(fn (TranslationStatus $s): array => [$s->value => $s->label()])->all())
                    ->query(fn (Builder $query, array $data): Builder => filled($data['value'] ?? null)
                        ? $query->whereHas('translations', fn (Builder $t) => $t->where('status', $data['value']))
                        : $query),
                SelectFilter::make('category_id')
                    ->label(__('Category'))
                    ->options(fn (): array => TaxonomyLabels::categoryOptions()),
                SelectFilter::make('author_id')
                    ->label(__('Author'))
                    ->options(fn (): array => User::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->visible(fn (): bool => auth()->user()->isReviewer()),
                TrashedFilter::make()
                    ->visible(fn (): bool => auth()->user()->isAdmin()),
            ])
            ->recordUrl(fn (Article $record): ?string => $record->trashed()
                ? null
                : ArticleResource::getUrl('edit', ['record' => $record]))
            ->recordActions([
                // Icon only on phones: the whole row is a link too, this just keeps a visible button.
                PhoneFriendly::iconOnPhone(EditAction::make()
                    ->label(fn (Article $record): string => auth()->user()->can('update', $record) ? __('Edit') : __('Open'))
                    ->icon(fn (Article $record): Heroicon => auth()->user()->can('update', $record) ? Heroicon::PencilSquare : Heroicon::Eye)
                    // On a phone only the icon shows, so say what the eye means.
                    ->tooltip(fn (Article $record): string => match (true) {
                        auth()->user()->can('update', $record) => __('Edit this story'),
                        self::notYourSection($record) !== null => __("Read only: this story's section is not one of yours"),
                        default => __('Read only: it is with the Editor-in-chief, or published'),
                    })),
                // Rare, destructive moves live behind one icon so the row never outgrows a laptop screen.
                ActionGroup::make([
                    RestoreAction::make(),
                    ArticleResource::deleteAction(),
                ]),
            ]);
    }

    /**
     * Phones only: the Arabic headline, or the English one when the story has no Arabic version.
     * Searching still goes through the two language columns (hidden, not removed, on a phone).
     */
    private static function phoneHeadlineColumn(): TextColumn
    {
        return TextColumn::make('headline')
            ->label(__('Headline'))
            ->state(fn (Article $record): ?string => ($record->translation(Locale::Ar) ?? $record->translation(Locale::En))?->title)
            ->placeholder('-')
            ->description(fn (Article $record): ?string => self::notYourSection($record))
            ->weight('bold')
            ->wrap()
            ->lineClamp(3)
            // Takes every pixel the status badges and the row button leave over.
            ->grow()
            ->extraAttributes(['dir' => 'auto', 'class' => 'sisi-headline'])
            ->hiddenFrom('md');
    }

    /**
     * "Not your section" for a journalist's own story in a section an admin took away (they may open it, not change
     * it); null for everyone and everything else. Reviewers write everywhere, so they never pay for the lookup.
     */
    private static function notYourSection(Article $record): ?string
    {
        $user = auth()->user();

        return $user->isReviewer() || $user->canWriteIn($record->category_id) ? null : __('Not your section');
    }

    private static function titleColumn(Locale $locale): TextColumn
    {
        $language = StatusPresenter::language($locale);

        return TextColumn::make("{$locale->value}_title")
            ->label(__(':language title', ['language' => $language]))
            ->state(fn (Article $record): ?string => $record->translation($locale)?->title)
            ->placeholder('-')
            // Two lines instead of a 36-character cut keeps the column narrow without hiding the headline.
            ->wrap()
            ->lineClamp(2)
            ->extraAttributes(['dir' => $locale->dir(), 'class' => 'sisi-headline'])
            // An RTL cell already starts on the right; only its header needs to move there to sit above the text.
            ->extraHeaderAttributes($locale === Locale::Ar ? ['class' => 'fi-align-end'] : [])
            ->searchable(query: fn (Builder $query, string $search): Builder => $query->whereHas(
                'translations',
                fn (Builder $t) => $t->where('locale', $locale->value)
                    ->where('title', 'like', '%'.addcslashes($search, '%_\\').'%'),
            ));
    }

    /**
     * One badge per language, stacked: two separate status columns pushed the row actions off screen.
     * A published version whose date is still ahead reads "Scheduled for ..." in its own colour.
     * The state is "locale:status:scheduled-timestamp" because a badge formatter only receives the state.
     */
    private static function versionsColumn(): TextColumn
    {
        return TextColumn::make('versions')
            ->label(__('Status'))
            ->badge()
            // On a phone the widest badge ("AR · Scheduled for 15 Oct, 09:30") breaks onto two lines: see admin-styles.
            ->listWithLineBreaks()
            ->state(fn (Article $record): array => array_map(function (Locale $locale) use ($record): string {
                $translation = $record->translation($locale);

                return implode(':', [
                    $locale->value,
                    $translation?->status->value ?? '',
                    StatusPresenter::isScheduled($translation) ? $translation->published_at->getTimestamp() : '',
                ]);
            }, Locale::cases()))
            ->formatStateUsing(function (string $state): string {
                [$locale, $status, $scheduledAt] = explode(':', $state, 3);

                return strtoupper($locale).' · '.($scheduledAt !== ''
                    ? __('Scheduled for :date', ['date' => StatusPresenter::when(CarbonImmutable::createFromTimestamp((int) $scheduledAt))])
                    : StatusPresenter::label(TranslationStatus::tryFrom($status)));
            })
            ->color(function (string $state): string {
                [, $status, $scheduledAt] = explode(':', $state, 3);

                return $scheduledAt !== ''
                    ? StatusPresenter::SCHEDULED_COLOR
                    : StatusPresenter::color(TranslationStatus::tryFrom($status));
            });
    }
}
