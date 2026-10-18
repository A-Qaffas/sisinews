<?php

namespace App\Filament\Pages;

use App\Enums\Locale;
use App\Filament\Support\StatusPresenter;
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

    protected static ?string $title = 'Site settings';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCog6Tooth;

    protected static string|BackedEnum|null $activeNavigationIcon = Heroicon::Cog6Tooth;

    protected static string|UnitEnum|null $navigationGroup = 'Site';

    protected static ?int $navigationSort = 2;

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public static function canAccess(): bool
    {
        return Gate::allows('manage', SiteSetting::class);
    }

    public function mount(): void
    {
        $this->fillForm();
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Masthead')
                    ->description('Printed beside the logo and at the bottom of every page, as in a newspaper. Leave a name empty to hide that line. English pages show only the English names, so until they are filled in English pages show no credits.')
                    ->columns(['default' => 1, 'md' => 2])
                    ->schema([
                        self::nameInput('chairman', Locale::Ar, 'Chairman of the Board'),
                        self::nameInput('chairman', Locale::En, 'Chairman of the Board'),
                        self::nameInput('editor', Locale::Ar, 'Editor-in-Chief'),
                        self::nameInput('editor', Locale::En, 'Editor-in-Chief'),
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
                        Action::make('save')->label('Save')->submit('save')->keyBindings(['mod+s']),
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

        Notification::make()->success()->title('Site settings saved')->send();
    }

    private function fillForm(): void
    {
        $settings = app(SiteSettings::class);

        $this->form->fill(collect(Masthead::keys())->mapWithKeys(fn (string $key): array => [$key => $settings->get($key)])->all());
    }

    private static function nameInput(string $role, Locale $locale, string $label): TextInput
    {
        return TextInput::make(Masthead::key($role, $locale))
            ->label($label.' ('.StatusPresenter::language($locale).')')
            ->rule('string')
            ->trim()
            ->maxLength(Masthead::MAX_LENGTH)
            ->autocomplete(false)
            ->extraInputAttributes(['dir' => $locale->dir(), 'lang' => $locale->value]);
    }
}
