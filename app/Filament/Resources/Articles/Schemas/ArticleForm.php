<?php

namespace App\Filament\Resources\Articles\Schemas;

use App\Editorial\Transition;
use App\Enums\Locale;
use App\Enums\TranslationStatus;
use App\Filament\Resources\Articles\Support\ArticleAccess;
use App\Filament\Support\StatusPresenter;
use App\Filament\Support\Taxonomy\TagWriter;
use App\Filament\Support\TaxonomyLabels;
use App\Models\Article;
use App\Models\Tag;
use App\Models\User;
use App\Support\Slugger;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\HtmlString;
use League\Flysystem\UnableToCheckFileExistence;

/**
 * Status is never a form field: workflow state changes only through the header
 * actions on the edit page, which call EditorialWorkflow.
 */
class ArticleForm
{
    private const IMAGE_TYPES = ['image/jpeg', 'image/png', 'image/webp'];

    private const IMAGE_MAX_KB = 5120;

    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                self::changesRequestedCallout(Locale::Ar),
                self::changesRequestedCallout(Locale::En),
                Grid::make(['default' => 1, 'xl' => 3])->schema([
                    Tabs::make('Languages')
                        ->tabs([self::languageTab(Locale::Ar), self::languageTab(Locale::En)])
                        ->activeTab(fn (?Article $record): int => self::tabWaitingOnUser($record))
                        ->columnSpan(['xl' => 2]),
                    Group::make([self::detailsSection(), self::imageSection()])
                        ->columnSpan(['xl' => 1]),
                ]),
            ]);
    }

    /** The reviewer's most recent note, shown above the form to whoever must act on it. */
    private static function changesRequestedCallout(Locale $locale): Callout
    {
        $language = StatusPresenter::language($locale);

        return Callout::make("Changes requested on the {$language} version")
            ->danger()
            ->description(function (?Article $record) use ($locale): ?HtmlString {
                $change = $record?->translation($locale)?->loadMissing('statusChanges.user')
                    ->statusChanges->firstWhere('transition', Transition::RequestChanges);
                if ($change === null) {
                    return null;
                }

                $by = e($change->user?->name ?? 'A reviewer');
                $at = e($change->created_at->timezone(StatusPresenter::TIMEZONE)->format('j M Y, H:i'));

                // The note is free text in either language inside an English page: dir=auto lets an Arabic note read right-to-left.
                return new HtmlString("<strong>{$by}</strong> on {$at}:<div dir=\"auto\">".nl2br(e((string) $change->note)).'</div>');
            })
            ->visible(fn (?Article $record): bool => $record?->translation($locale)?->status === TranslationStatus::ChangesRequested);
    }

    private static function languageTab(Locale $locale): Tab
    {
        return Tab::make(StatusPresenter::language($locale))
            ->badge(fn (?Article $record): ?string => StatusPresenter::shortLabel($record?->translation($locale)))
            ->badgeColor(fn (?Article $record): string => StatusPresenter::stateColor($record?->translation($locale)))
            ->schema([
                View::make('filament.forms.translation-status')
                    ->viewData(fn (?Article $record): array => [
                        'translation' => $record?->translation($locale)?->loadMissing('statusChanges.user'),
                        'locale' => $locale,
                        'canEdit' => ArticleAccess::canEditTranslation(self::user(), $record, $locale),
                        'lostSection' => ArticleAccess::lostSectionName(self::user(), $record),
                    ])
                    ->visible(fn (?Article $record): bool => $record !== null),
                self::translationFields($locale),
            ]);
    }

    /**
     * Opens the story on the language that needs this person next: a reviewer's pending review,
     * an author's draft or sent-back text. Otherwise the first tab, as before.
     */
    private static function tabWaitingOnUser(?Article $record): int
    {
        if ($record === null) {
            return 1;
        }

        $user = self::user();

        foreach (Locale::cases() as $index => $locale) {
            $status = $record->translation($locale)?->status;
            $waiting = $user->isReviewer()
                ? $status === TranslationStatus::InReview
                : $status?->isAuthorEditable() === true && ArticleAccess::canEditTranslation($user, $record, $locale);

            if ($waiting) {
                return $index + 1;
            }
        }

        return 1;
    }

    private static function translationFields(Locale $locale): Group
    {
        $language = StatusPresenter::language($locale);
        $direction = ['dir' => $locale->dir(), 'lang' => $locale->value];

        return Group::make([
            TextInput::make('title')
                ->label('Headline')
                ->maxLength(255)
                ->required(fn (Get $get, ?Article $record): bool => self::languageIsInUse($locale, $get, $record)
                    || ($locale === Locale::Ar && ! self::languageIsInUse(Locale::En, $get, $record, '../en.')))
                ->live(onBlur: true)
                ->afterStateUpdated(function (Get $get, Set $set, ?string $state, ?string $old): void {
                    $slug = trim((string) $get('slug'));
                    if ($slug === '' || $slug === Slugger::make((string) $old)) {
                        $set('slug', Slugger::make((string) $state));
                    }
                })
                ->extraInputAttributes($direction),
            TextInput::make('slug')
                ->label('URL slug')
                ->helperText('Built from the headline when left blank. It only decorates the address; the story is found by its number.')
                ->maxLength(255)
                ->extraInputAttributes($direction),
            Textarea::make('excerpt')
                ->label('Summary')
                ->helperText("Shown on cards and in search results ({$language}).")
                ->rows(3)
                ->maxLength(500)
                ->extraInputAttributes($direction),
            RichEditor::make('body')
                ->label('Story')
                ->toolbarButtons([
                    ['bold', 'italic', 'underline', 'link'],
                    ['h2', 'h3'],
                    ['blockquote', 'bulletList', 'orderedList', 'horizontalRule'],
                    ['attachFiles'],
                    ['undo', 'redo'],
                ])
                ->linkProtocols(['http', 'https', 'mailto'])
                // Filament's upload endpoint for pictures in the text ignores the disabled state, so the same rule that
                // locks the field must switch uploads off; otherwise a locked version could still put files on the public disk.
                ->fileAttachments(fn (?Article $record): bool => ArticleAccess::canEditTranslation(self::user(), $record, $locale))
                ->getFileAttachmentUrlUsing(fn (mixed $file): ?string => self::attachmentUrl($file))
                ->fileAttachmentsDisk('public')
                ->fileAttachmentsDirectory('articles/inline')
                ->fileAttachmentsVisibility('public')
                ->fileAttachmentsAcceptedFileTypes(self::IMAGE_TYPES)
                ->fileAttachmentsMaxSize(self::IMAGE_MAX_KB)
                ->minHeight('20rem')
                ->extraInputAttributes($direction),
            TextInput::make('hero_caption')
                ->label('Image caption')
                ->maxLength(255)
                ->extraInputAttributes($direction),
            Textarea::make('meta_description')
                ->label('Search engine description')
                ->rows(2)
                ->maxLength(300)
                ->extraInputAttributes($direction),
        ])
            ->statePath($locale->value)
            ->disabled(fn (?Article $record): bool => ! ArticleAccess::canEditTranslation(self::user(), $record, $locale));
    }

    /**
     * A language is "in use" once it has a stored version or any field is filled; only then
     * does it need a headline. A story may exist in one language only.
     *
     * @param  string  $prefix  path to the language group relative to the calling field
     */
    private static function languageIsInUse(Locale $locale, Get $get, ?Article $record, string $prefix = ''): bool
    {
        if ($record?->translation($locale) !== null) {
            return true;
        }

        foreach (['title', 'slug', 'excerpt', 'hero_caption', 'meta_description'] as $field) {
            if (filled(trim((string) $get($prefix.$field)))) {
                return true;
            }
        }

        $body = $get($prefix.'body');

        return is_string($body) && trim(strip_tags($body)) !== '';
    }

    private static function detailsSection(): Section
    {
        return Section::make('Story details')->schema([
            Select::make('category_id')
                ->label('Category')
                // A journalist is offered only the sections an admin assigned; Filament also rejects any other id on save.
                ->options(fn (?Article $record): array => ArticleAccess::categoryOptions(self::user(), $record))
                // A journalist with exactly one section has nothing to choose: start the new story there.
                ->default(function (): ?int {
                    $options = ArticleAccess::categoryOptions(self::user(), null);

                    return count($options) === 1 ? (int) array_key_first($options) : null;
                })
                ->required()
                ->searchable(),
            Select::make('tags')
                ->label('Tags')
                ->multiple()
                ->searchable()
                ->getSearchResultsUsing(fn (string $search): array => TaxonomyLabels::tagOptions(
                    Tag::query()
                        ->with('translations')
                        ->whereHas('translations', fn ($q) => $q->where('name', 'like', '%'.addcslashes($search, '%_\\').'%'))
                        ->limit(50)
                        ->get(),
                ))
                ->getOptionLabelsUsing(fn (array $values): array => TaxonomyLabels::tagOptions(
                    Tag::query()->with('translations')->whereKey($values)->get(),
                ))
                // The default "Create" heading and button label do not say what is being created.
                ->createOptionAction(fn (Action $action): Action => $action
                    ->label('New tag')
                    ->tooltip('Add a new tag')
                    ->modalHeading('New tag')
                    ->modalDescription('Write the name in at least one language.'))
                ->createOptionForm([
                    TextInput::make('name_ar')
                        ->label('Name (Arabic)')
                        ->maxLength(120)
                        ->requiredWithout('name_en')
                        ->extraInputAttributes(['dir' => 'rtl', 'lang' => 'ar']),
                    TextInput::make('name_en')
                        ->label('Name (English)')
                        ->maxLength(120)
                        ->requiredWithout('name_ar'),
                ])
                ->createOptionUsing(fn (array $data): int => app(TagWriter::class)
                    ->findOrCreate($data['name_en'] ?? null, $data['name_ar'] ?? null)
                    ->getKey()),
            Select::make('author_id')
                ->label('Author')
                ->options(fn (?Article $record): array => self::authorOptions($record))
                ->default(fn (): int => self::user()->getKey())
                ->required()
                ->searchable()
                ->visible(fn (): bool => self::user()->isReviewer())
                ->disabled(fn (?Article $record): bool => ! ArticleAccess::canEditShell(self::user(), $record)),
            Toggle::make('is_featured')
                ->label('Featured on the front page'),
            Toggle::make('is_breaking')
                ->label('Breaking news')
                ->live(),
            DateTimePicker::make('breaking_until')
                ->label('Breaking until')
                ->helperText('Cairo time. Leave empty to keep it breaking until someone clears the flag.')
                ->timezone(StatusPresenter::TIMEZONE)
                ->seconds(false)
                ->visible(fn (Get $get): bool => (bool) $get('is_breaking')),
        ])->disabled(fn (?Article $record): bool => ! ArticleAccess::canEditShell(self::user(), $record));
    }

    private static function imageSection(): Section
    {
        return Section::make('Hero image')->schema([
            FileUpload::make('hero_image')
                ->label('Image')
                ->helperText('JPG, PNG or WebP, up to 5 MB.')
                ->disk('public')
                ->directory('articles')
                ->visibility('public')
                // image() first: it sets image/*, which acceptedFileTypes() then narrows (SVG can carry script).
                ->image()
                ->acceptedFileTypes(self::IMAGE_TYPES)
                ->maxSize(self::IMAGE_MAX_KB)
                // A locked story shows its picture without the remove and pencil buttons, which would look usable.
                ->imageEditor(fn (?Article $record): bool => ArticleAccess::canEditShell(self::user(), $record))
                ->deletable(fn (?Article $record): bool => ArticleAccess::canEditShell(self::user(), $record))
                // Phone photos are 12+ MP; the 128 MB host cannot decode those with GD, so shrink in the browser first.
                ->automaticallyResizeImagesMode('contain')
                ->automaticallyResizeImagesToWidth('1600')
                ->automaticallyResizeImagesToHeight('1600')
                ->automaticallyUpscaleImagesWhenResizing(false)
                ->preventFilePathTampering(),
        ])->disabled(fn (?Article $record): bool => ! ArticleAccess::canEditShell(self::user(), $record));
    }

    /**
     * Active staff, plus the story's current author even when that account is inactive (the launch stories are
     * credited to the inactive desk account): Filament refuses a value that is not among the options, so leaving the
     * byline out would make every save of such a story fail. ArticleWriter still refuses to MOVE a story to an
     * inactive account; keeping the same author is not a move.
     *
     * @return array<int, string> user id => name
     */
    private static function authorOptions(?Article $record): array
    {
        return User::query()
            ->where(fn ($query) => $query
                ->where('is_active', true)
                ->when($record?->author_id, fn ($query, int $authorId) => $query->orWhere('id', $authorId)))
            ->orderBy('name')
            ->get(['id', 'name', 'is_active'])
            ->mapWithKeys(fn (User $user): array => [$user->getKey() => $user->is_active ? $user->name : "{$user->name} (inactive)"])
            ->all();
    }

    /**
     * Filament's own address lookup for a picture in the text (public disk, so no temporary URL), plus a guard: with
     * uploads switched off the upload endpoint stores nothing and then asks for the address of nothing, which
     * Filament would turn into a type error (a 500) instead of a quiet "no".
     */
    private static function attachmentUrl(mixed $file): ?string
    {
        if (! is_string($file) || $file === '') {
            return null;
        }

        $disk = Storage::disk('public');

        try {
            return $disk->exists($file) ? $disk->url($file) : null;
        } catch (UnableToCheckFileExistence) {
            return null;
        }
    }

    private static function user(): User
    {
        return auth()->user();
    }
}
