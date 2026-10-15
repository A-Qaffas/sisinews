<?php

namespace App\Filament\Pages\Auth;

use App\Filament\Support\PasswordRule;
use Filament\Auth\Pages\EditProfile as BaseEditProfile;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use SensitiveParameter;

/**
 * Staff may change their display names and their own password. Email, role and
 * status are admin-managed (v1 has no email, so an address change cannot be verified).
 */
class EditProfile extends BaseEditProfile
{
    public function form(Schema $schema): Schema
    {
        return $schema->components([
            $this->getNameFormComponent(),
            TextInput::make('name_ar')
                ->label('Name in Arabic')
                ->maxLength(255)
                ->extraInputAttributes(['dir' => 'rtl', 'lang' => 'ar']),
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
        // Whitelist, so a crafted payload can never touch role, is_active or email.
        $record->update(Arr::only($data, ['name', 'name_ar', 'password']));

        return $record;
    }
}
