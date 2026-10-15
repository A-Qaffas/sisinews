<?php

namespace App\Filament\Resources\Users\Schemas;

use App\Enums\Role;
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
use Filament\Schemas\Schema;

class UserForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make('Account')
                    ->columns(2)
                    ->schema([
                        TextInput::make('name')
                            ->label('Name')
                            ->required()
                            ->maxLength(255),
                        TextInput::make('name_ar')
                            ->label('Name in Arabic')
                            ->helperText('Shown on Arabic bylines.')
                            ->maxLength(255)
                            ->extraInputAttributes(['dir' => 'rtl', 'lang' => 'ar']),
                        TextInput::make('email')
                            ->label('Email (sign-in name)')
                            ->email()
                            ->required()
                            ->maxLength(255)
                            ->unique(ignoreRecord: true)
                            // The byline address must stay a non-person: an active account on it would be a sign-in nobody expects.
                            ->rule(fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
                                if (TeamGuard::isReservedEmail(is_string($value) ? $value : null)) {
                                    $fail('This address is reserved for the newsroom byline and cannot belong to a team member.');
                                }
                            }),
                        // Only when creating. Afterwards a password changes through "Reset password",
                        // which asks twice, so there is exactly one way to set someone else's password.
                        TextInput::make('password')
                            ->label('Password')
                            ->helperText('At least 10 characters, with letters and numbers. Tell the person yourself: the site sends no email.')
                            ->password()
                            ->revealable()
                            ->autocomplete('new-password')
                            ->required()
                            ->rule(PasswordRule::make())
                            ->visible(fn (?User $record): bool => $record === null),
                    ]),
                Section::make('Access')
                    ->columns(2)
                    ->schema([
                        Select::make('role')
                            ->options(collect(Role::cases())->mapWithKeys(fn (Role $role): array => [$role->value => $role->label()])->all())
                            ->default(Role::Journalist->value)
                            ->required()
                            ->selectablePlaceholder(false)
                            ->helperText('Journalists write in their own sections and cannot publish; the Editor-in-chief also approves and publishes; admins also manage staff.')
                            // Live, so the section picker below appears for journalists only.
                            ->live()
                            ->disabled(fn (?User $record): bool => self::isCurrentUser($record)),
                        Toggle::make('is_active')
                            ->label('Active')
                            ->helperText(fn (?User $record): string => self::isCurrentUser($record)
                                ? 'You cannot deactivate your own account.'
                                : 'Deactivated staff can no longer sign in.')
                            ->default(true)
                            ->disabled(fn (?User $record): bool => self::isCurrentUser($record)),
                        // Hidden (so not submitted either) for the Editor-in-chief and admins, who write in every section.
                        // What was ticked before stays stored while hidden, so changing the role back loses nothing.
                        Select::make(SectionAssignments::STATE)
                            ->label('Sections this journalist may write in')
                            ->multiple()
                            ->searchable()
                            ->preload()
                            ->options(fn (): array => TaxonomyLabels::categoryOptions())
                            ->placeholder('None yet')
                            ->live()
                            ->helperText(fn (Get $get): string => blank($get(SectionAssignments::STATE))
                                ? 'Until at least one section is chosen, this journalist cannot start a story.'
                                : 'The journalist can start and edit stories only in these sections.')
                            ->columnSpanFull()
                            ->visible(fn (Get $get): bool => SectionAssignments::applies($get('role'))),
                    ]),
            ]);
    }

    private static function isCurrentUser(?User $record): bool
    {
        return $record !== null && $record->is(auth()->user());
    }
}
