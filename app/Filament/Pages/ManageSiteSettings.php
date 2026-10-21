<?php

namespace App\Filament\Pages;

use App\Enums\Locale;
use App\Filament\Support\AdminNavigationGroup;
use App\Models\SiteSetting;
use App\Services\SiteSettings;
use App\Support\Masthead;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Concerns\CanUseDatabaseTransactions;
use Filament\Pages\Concerns\HasUnsavedDataChangesAlert;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Gate;
use UnitEnum;

/**
 * Site > Site settings: values printed on every public page, for now the masthead credits. Active administrators only
 * (SiteSettingPolicy): everyone else gets no menu item and a 403, on the first load and on every Livewire call.
 *
 * @property-read Schema $form
 */
class ManageSiteSettings extends Page
{
    use CanUseDatabaseTransactions;
    use HasUnsavedDataChangesAlert;

    protected static ?string $slug = 'site-settings';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCog6Tooth;

    protected static string|BackedEnum|null $activeNavigationIcon = Heroicon::Cog6Tooth;

    protected static string|UnitEnum|null $navigationGroup = AdminNavigationGroup::Site;

    protected static ?int $navigationSort = 2;

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public static function canAccess(): bool
    {
        return Gate::allows('manage', SiteSetting::class);
    }

    public function getTitle(): string|Htmlable
    {
        return __('Site settings');
    }

    // Filament reads the menu label from the static $title, which a translated string cannot be.
    public static function getNavigationLabel(): string
    {
        return __('Site settings');
    }

    public function mount(): void
    {
        $this->fillForm();
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('Masthead'))
                    ->description(__('Printed beside the logo and at the bottom of every page, as in a newspaper. Leave a name empty to hide that line. English pages show only the English names, so until they are filled in English pages show no credits.'))
                    ->columns(['default' => 1, 'md' => 2])
                    ->schema([
                        self::nameInput('chairman', Locale::Ar, __('Chairman of the Board (Arabic)')),
                        self::nameInput('chairman', Locale::En, __('Chairman of the Board (English)')),
                        self::nameInput('editor', Locale::Ar, __('Editor-in-Chief (Arabic)')),
                        self::nameInput('editor', Locale::En, __('Editor-in-Chief (English)')),
                    ]),
            ])
            ->statePath('data');
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Form::make([EmbeddedSchema::make('form')])
                ->id('form')
                ->livewireSubmitHandler('save')
                ->footer([
                    Actions::make([
                        Action::make('save')->label(__('Save'))->submit('save')->keyBindings(['mod+s']),
                    ])->key('form-actions'),
                ]),
        ]);
    }

    public function save(): void
    {
        // Livewire already re-checks access on every call (CanAuthorizeAccess); this keeps save() safe on its own.
        abort_unless(static::canAccess(), 403);

        $data = $this->form->getState();
        $settings = app(SiteSettings::class);

        // Only the known keys: a crafted payload with extra "data.*" entries cannot create other settings.
        $this->wrapInDatabaseTransaction(function () use ($data, $settings): void {
            foreach (Masthead::keys() as $key) {
                $value = $data[$key] ?? null;
                $settings->set($key, is_string($value) ? $value : null);
            }
        });

        $this->fillForm();
        $this->rememberData();

        Notification::make()->success()->title(__('Site settings saved'))->send();
    }

    private function fillForm(): void
    {
        $settings = app(SiteSettings::class);

        $this->form->fill(collect(Masthead::keys())->mapWithKeys(fn (string $key): array => [$key => $settings->get($key)])->all());
    }

    /** @param  string  $label  translated, naming the role and the language */
    private static function nameInput(string $role, Locale $locale, string $label): TextInput
    {
        return TextInput::make(Masthead::key($role, $locale))
            ->label($label)
            ->rule('string')
            ->trim()
            ->maxLength(Masthead::MAX_LENGTH)
            ->autocomplete(false)
            ->extraInputAttributes(['dir' => $locale->dir(), 'lang' => $locale->value]);
    }
}
