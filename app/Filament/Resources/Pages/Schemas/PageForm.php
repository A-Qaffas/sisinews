<?php

namespace App\Filament\Resources\Pages\Schemas;

use App\Enums\Locale;
use App\Filament\Support\StatusPresenter;
use App\Models\Page;
use App\Support\PageBody;
use Closure;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

/**
 * Two language tabs (Arabic first) plus the details that belong to the page as a whole. The editor is configured
 * like the article editor (same toolbar minus image uploads, same dir/lang per language) and what it offers stays
 * inside the HtmlSanitizer allow-list, so nothing the toolbar produces is stripped on save.
 */
class PageForm
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

    private static function languageTab(Locale $locale): Tab
    {
        return Tab::make(StatusPresenter::language($locale))
            ->badge(fn (?Page $record): ?string => match ($record?->translate($locale)?->is_published) {
                true => __('Published'),
                false => __('Hidden'),
                null => null,
            })
            ->badgeColor(fn (?Page $record): string => $record?->translate($locale)?->is_published === true ? 'success' : 'gray')
            ->schema([self::translationFields($locale)]);
    }

    private static function translationFields(Locale $locale): Group
    {
        $direction = ['dir' => $locale->dir(), 'lang' => $locale->value];

        return Group::make([
            TextInput::make('title')
                ->label(__('Title'))
                ->maxLength(255)
                ->required(fn (Get $get, ?Page $record): bool => self::languageIsInUse($locale, $get, $record)
                    || ($locale === Locale::Ar && ! self::languageIsInUse(Locale::En, $get, $record, '../en.')))
                ->extraInputAttributes($direction),
            RichEditor::make('body')
                ->label(__('Text'))
                ->helperText(__('Write :token where readers should be pointed to the contact address; the site fills it in.', ['token' => PageBody::CONTACT_TOKEN]))
                ->toolbarButtons([
                    ['bold', 'italic', 'underline', 'link'],
                    ['h2', 'h3'],
                    ['blockquote', 'bulletList', 'orderedList', 'horizontalRule'],
                    ['undo', 'redo'],
                ])
                ->linkProtocols(['http', 'https', 'mailto'])
                ->minHeight('20rem')
                ->extraInputAttributes($direction)
                ->rules([fn (Get $get, ?Page $record): Closure => function (string $attribute, mixed $value, Closure $fail) use ($locale, $get, $record): void {
                    if (self::languageIsInUse($locale, $get, $record) && ! self::hasText($value)) {
                        $fail(__('The text cannot be empty.'));
                    }
                }]),
            Textarea::make('meta_description')
                ->label(__('Search engine description'))
                ->helperText(__('One or two sentences shown under the title in search results. Left blank, the start of the text is used.'))
                ->rows(2)
                ->maxLength(300)
                ->extraInputAttributes($direction),
            Toggle::make('is_published')
                ->label(__('Published in this language'))
                ->helperText(__('Turn off to take this language off the site; its address then shows "page not found". The text is kept.'))
                ->default(true),
        ])->statePath($locale->value);
    }

    private static function detailsSection(): Section
    {
        return Section::make(__('Page details'))->schema([
            TextInput::make('slug')
                ->label(__('Address'))
                ->helperText(__('Lowercase letters, numbers and hyphens, for example "ownership". The page is then reachable at /ar/page/ownership and /en/page/ownership. It cannot be changed after the page is created.'))
                ->required()
                ->maxLength(80)
                ->regex('/^'.Page::SLUG_PATTERN.'$/')
                ->unique(Page::class, 'slug', ignoreRecord: true)
                ->live(onBlur: true)
                ->afterStateUpdated(fn (Set $set, ?string $state) => $set('slug', Str::lower(trim((string) $state))))
                // Fixed once the page exists; the writer ignores the slug on update as well.
                ->disabled(fn (?Page $record): bool => $record !== null)
                ->dehydrated(fn (?Page $record): bool => $record === null)
                ->extraInputAttributes(['dir' => 'ltr']),
            TextInput::make('sort_order')
                ->label(__('Position'))
                ->helperText(__('Lower numbers come first in the footer.'))
                ->numeric()
                ->integer()
                ->minValue(0)
                ->maxValue(65535)
                ->default(0)
                ->required(),
            Toggle::make('show_in_footer')
                ->label(__('Link from the footer'))
                ->helperText(__('Shown only in languages where the page is published.'))
                ->default(true),
        ]);
    }

    /**
     * A language is "in use" once it has a stored version or any field is filled; only then does it need a title
     * and text. A page may exist in one language only.
     *
     * @param  string  $prefix  path to the language group relative to the calling field
     */
    private static function languageIsInUse(Locale $locale, Get $get, ?Page $record, string $prefix = ''): bool
    {
        if ($record?->translate($locale) !== null) {
            return true;
        }

        foreach (['title', 'meta_description'] as $field) {
            if (filled(trim((string) $get($prefix.$field)))) {
                return true;
            }
        }

        return self::hasText($get($prefix.'body'));
    }

    /**
     * An empty editor still holds markup such as "<p></p>", so look for text. While the form is open the editor's state
     * is its TipTap document (nested arrays whose text lives in "text" keys); an HTML string is accepted too.
     */
    private static function hasText(mixed $body): bool
    {
        if (is_array($body)) {
            $text = '';
            array_walk_recursive($body, function (mixed $value, mixed $key) use (&$text): void {
                if ($key === 'text' && is_string($value)) {
                    $text .= $value;
                }
            });
            $body = $text;
        } elseif (is_string($body)) {
            $body = html_entity_decode(strip_tags($body), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        } else {
            return false;
        }

        return preg_replace('/[\s\x{00A0}]+/u', '', $body) !== '';
    }
}
