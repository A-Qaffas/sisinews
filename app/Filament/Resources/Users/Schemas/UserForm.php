<?php

namespace App\Filament\Resources\Users\Schemas;

use App\Enums\Locale;
use App\Enums\Permission;
use App\Enums\Role;
use App\Filament\Resources\Users\Support\PermissionFields;
use App\Filament\Resources\Users\Support\SectionAssignments;
use App\Filament\Resources\Users\Support\TeamGuard;
use App\Filament\Support\PasswordRule;
use App\Filament\Support\TaxonomyLabels;
use App\Models\User;
use Closure;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;

class UserForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make(__('Account'))
                    ->columns(2)
                    ->schema([
                        TextInput::make('name')
                            ->label(__('Name'))
                            ->required()
                            ->maxLength(255)
                            // The Latin-script name types left-to-right even when the panel is Arabic.
                            ->extraInputAttributes(['dir' => 'ltr', 'lang' => 'en']),
                        TextInput::make('name_ar')
                            ->label(__('Name in Arabic'))
                            ->helperText(__('Shown on Arabic bylines.'))
                            ->maxLength(255)
                            ->extraInputAttributes(['dir' => 'rtl', 'lang' => 'ar']),
                        TextInput::make('email')
                            ->label(__('Email (sign-in name)'))
                            ->email()
                            ->extraInputAttributes(['dir' => 'ltr'])
                            ->required()
                            ->maxLength(255)
                            ->unique(ignoreRecord: true)
                            // The byline address must stay a non-person: an active account on it would be a sign-in nobody expects.
                            ->rule(fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
                                if (TeamGuard::isReservedEmail(is_string($value) ? $value : null)) {
                                    $fail(__('This address is reserved for the newsroom byline and cannot belong to a team member.'));
                                }
                            }),
                        // Only when creating. Afterwards a password changes through "Reset password",
                        // which asks twice, so there is exactly one way to set someone else's password.
                        TextInput::make('password')
                            ->label(__('Password'))
                            ->helperText(__('At least 10 characters, with letters and numbers. Tell the person yourself: the site sends no email.'))
                            ->password()
                            ->revealable()
                            ->autocomplete('new-password')
                            ->required()
                            ->rule(PasswordRule::make())
                            ->visible(fn (?User $record): bool => $record === null),
                    ]),
                Section::make(__('Access'))
                    ->columns(2)
                    ->schema([
                        Select::make('role')
                            ->label(__('Role'))
                            ->options(collect(Role::cases())->mapWithKeys(fn (Role $role): array => [$role->value => $role->label()])->all())
                            ->default(Role::Journalist->value)
                            ->required()
                            ->selectablePlaceholder(false)
                            ->helperText(__('Journalists write in their own sections and cannot publish; the Editor-in-chief also approves and publishes; admins also manage staff.'))
                            // Choosing a role ticks its ready-made permissions below; the admin can then change single ones.
                            ->live()
                            ->afterStateUpdated(fn (Set $set, mixed $state) => PermissionFields::resetTo($set, $state))
                            ->disabled(fn (?User $record): bool => self::isCurrentUser($record)),
                        Toggle::make('is_active')
                            ->label(__('Active'))
                            ->helperText(fn (?User $record): string => self::isCurrentUser($record)
                                ? __('You cannot deactivate your own account.')
                                : __('Deactivated staff can no longer sign in.'))
                            ->default(true)
                            ->disabled(fn (?User $record): bool => self::isCurrentUser($record)),
                        // New staff start in Arabic (most of the newsroom does not read English); each person can switch.
                        Select::make('locale')
                            ->label(__('Dashboard language'))
                            ->options([Locale::Ar->value => Locale::Ar->label(), Locale::En->value => Locale::En->label()])
                            ->default(Locale::Ar->value)
                            ->formatStateUsing(fn (?User $record, mixed $state): string => $state instanceof Locale ? $state->value : ($record?->adminLocale()->value ?? Locale::Ar->value))
                            ->selectablePlaceholder(false)
                            ->required()
                            ->helperText(__('What this person sees in the dashboard. They can also switch it from their own menu.')),
                        // Hidden (so not submitted either) for anyone who may write in every section, or not write at all.
                        // What was ticked before stays stored while hidden, so ticking the permission back loses nothing.
                        Select::make(SectionAssignments::STATE)
                            ->label(__('Sections this journalist may write in'))
                            ->multiple()
                            ->searchable()
                            ->preload()
                            ->options(fn (): array => TaxonomyLabels::categoryOptions())
                            ->placeholder(__('None yet'))
                            ->live()
                            ->helperText(fn (Get $get): string => blank($get(SectionAssignments::STATE))
                                ? __('Until at least one section is chosen, this journalist cannot start a story.')
                                : __('The journalist can start and edit stories only in these sections.'))
                            ->columnSpanFull()
                            ->visible(fn (Get $get): bool => PermissionFields::ticked($get, Permission::WriteStories)
                                && ! PermissionFields::ticked($get, Permission::WriteInAllSections)),
                    ]),
                PermissionFields::section(),
            ]);
    }

    private static function isCurrentUser(?User $record): bool
    {
        return $record !== null && $record->is(auth()->user());
    }
}
