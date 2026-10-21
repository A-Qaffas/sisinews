<?php

namespace App\Filament\Pages\Auth;

use App\Enums\Locale;
use App\Filament\Support\PasswordRule;
use App\Models\User;
use Filament\Auth\Pages\EditProfile as BaseEditProfile;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use SensitiveParameter;

/**
 * Staff may change their display names, the dashboard's language and their own password. Email, role and
 * status are admin-managed (v1 has no email, so an address change cannot be verified).
 */
class EditProfile extends BaseEditProfile
{
    /** Set when the language changed, so the page reloads in it after saving. */
    private bool $languageChanged = false;

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            // The Latin-script name types left-to-right even when the panel is Arabic.
            $this->getNameFormComponent()->extraInputAttributes(['dir' => 'ltr', 'lang' => 'en']),
            TextInput::make('name_ar')
                ->label(__('Name in Arabic'))
                ->maxLength(255)
                ->extraInputAttributes(['dir' => 'rtl', 'lang' => 'ar']),
            Select::make('locale')
                ->label(__('Dashboard language'))
                ->options([Locale::Ar->value => Locale::Ar->label(), Locale::En->value => Locale::En->label()])
                ->formatStateUsing(fn (mixed $state): string => $state instanceof Locale ? $state->value : $this->currentLocale()->value)
                ->selectablePlaceholder(false)
                ->required(),
            $this->getPasswordFormComponent(),
            $this->getPasswordConfirmationFormComponent(),
            $this->getCurrentPasswordFormComponent(),
        ]);
    }

    protected function getPasswordFormComponent(): Component
    {
        return parent::getPasswordFormComponent()->rule(PasswordRule::make());
    }

    protected function getCurrentPasswordFormComponent(): Component
    {
        return parent::getCurrentPasswordFormComponent()
            ->visible(fn (Get $get): bool => filled($get('password')));
    }

    /** @param  array<string, mixed>  $data */
    protected function handleRecordUpdate(Model $record, #[SensitiveParameter] array $data): Model
    {
        $before = $this->currentLocale();

        // Whitelist, so a crafted payload can never touch role, is_active or email.
        $record->update(Arr::only($data, ['name', 'name_ar', 'locale', 'password']));

        $this->languageChanged = $this->currentLocale() !== $before;

        return $record;
    }

    protected function getRedirectUrl(): ?string
    {
        return $this->languageChanged ? static::getUrl() : parent::getRedirectUrl();
    }

    private function currentLocale(): Locale
    {
        $user = $this->getUser();

        return $user instanceof User ? $user->adminLocale() : Locale::default();
    }
}
