<?php

namespace App\Filament\Resources\LiveStreams\Schemas;

use App\Enums\Locale;
use App\Filament\Resources\LiveStreams\Support\LiveStreamWriter;
use App\Filament\Support\StatusPresenter;
use App\Models\Governorate;
use Closure;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

/**
 * Two language tabs (Arabic first) for the titles and the broadcast's own details beside them. There is no status
 * field: a broadcast only goes live or ends through the Go live and End buttons (LiveStream::goLive()/end()).
 */
class LiveStreamForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Grid::make(['default' => 1, 'xl' => 3])->schema([
                    Tabs::make(__('Languages'))
                        ->tabs([self::languageTab(Locale::Ar), self::languageTab(Locale::En)])
                        ->columnSpan(['xl' => 2]),
                    self::detailsSection()->columnSpan(['xl' => 1]),
                ]),
            ]);
    }

    /** "Cairo · القاهرة", in the order the Live page lists them; either name finds it when typing in the box. */
    public static function governorateOptions(): array
    {
        return Governorate::query()
            ->orderBy('sort_order')
            ->get(['id', 'name_en', 'name_ar'])
            ->mapWithKeys(fn (Governorate $governorate): array => [$governorate->getKey() => "{$governorate->name_en} · {$governorate->name_ar}"])
            ->all();
    }

    private static function languageTab(Locale $locale): Tab
    {
        return Tab::make(StatusPresenter::language($locale))->schema([self::translationFields($locale)]);
    }

    private static function translationFields(Locale $locale): Group
    {
        $direction = ['dir' => $locale->dir(), 'lang' => $locale->value];
        $arabic = $locale === Locale::Ar;
        $other = $locale->other()->value;

        return Group::make([
            TextInput::make('title')
                ->label(__('Title'))
                ->validationAttribute($arabic ? __('Arabic title') : __('English title'))
                ->trim()
                ->maxLength(160)
                // A broadcast needs a title in at least one language; once a language has a description it needs one too.
                ->required(fn (Get $get): bool => blank(trim((string) $get("../{$other}.title"))) || filled(trim((string) $get('description'))))
                ->extraInputAttributes($direction),
            Textarea::make('description')
                ->label(__('Description'))
                ->validationAttribute($arabic ? __('Arabic description') : __('English description'))
                ->helperText(__('Optional. Plain text: a line or two about what viewers are watching.'))
                ->rows(3)
                ->maxLength(1000)
                ->extraInputAttributes($direction),
        ])->statePath($locale->value);
    }

    private static function detailsSection(): Section
    {
        return Section::make(__('Broadcast details'))->schema([
            Select::make('governorate_id')
                ->label(__('Governorate'))
                ->options(fn (): array => self::governorateOptions())
                ->placeholder(__('Egypt-wide'))
                ->helperText(__('Leave on "Egypt-wide" for a broadcast that is not about one governorate.'))
                ->searchable(),
            TextInput::make('video_link')
                ->label(__('Video link or ID'))
                ->required()
                ->trim()
                ->maxLength(300)
                ->placeholder('https://www.youtube.com/watch?v=...')
                ->helperText(fn (Get $get): string => self::detected($get('video_link')))
                ->live(onBlur: true)
                ->rules([fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
                    if (! is_string($value)) {
                        $fail(__(LiveStreamWriter::LINK_ERROR));
                    } elseif (LiveStreamWriter::parseLink($value) === null) {
                        $fail(LiveStreamWriter::refusal($value));
                    }
                }])
                ->extraInputAttributes(['dir' => 'ltr']),
            DateTimePicker::make('scheduled_at')
                ->label(__('Scheduled for'))
                ->helperText(__('Optional, Cairo time. Only announces when you expect to start: the broadcast goes live when you press Go live.'))
                ->timezone(StatusPresenter::TIMEZONE)
                ->seconds(false)
                ->maxDate(fn (): string => now(StatusPresenter::TIMEZONE)->addYear()->format('Y-m-d H:i:s')),
        ]);
    }

    /** What the box understood, so a pasted link can be checked before saving. */
    private static function detected(mixed $input): string
    {
        if (! is_string($input) || trim($input) === '') {
            return __("Paste a YouTube or Vimeo link to the broadcast itself, or just the video ID. A channel's live page or a Vimeo event link will not work.");
        }

        $parsed = LiveStreamWriter::parseLink($input);

        if ($parsed !== null) {
            return __('Recognised: :provider video :id.', ['provider' => $parsed[0]->label(), 'id' => $parsed[1]]);
        }

        $reason = LiveStreamWriter::refusal($input);

        return $reason === __(LiveStreamWriter::LINK_ERROR)
            ? __('Not recognised: only YouTube and Vimeo video links (https) or IDs work.')
            : __('Not recognised. :reason', ['reason' => $reason]);
    }
}
