<?php

namespace App\Filament\Resources\Users\Support;

use App\Filament\Support\PasswordRule;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Collection;

/**
 * The account-control moves of the Team area, shared by the list rows, the edit page and the bulk menu.
 *
 * Every move is authorized twice: the Filament `authorize()` (UserPolicy::update, admins only) and,
 * for your own account, a refusal inside the action body, because `visible()` only hides a button and
 * an admin who could lock themselves out would be a support call. Switching an account off also goes
 * through TeamGuard, so the last active administrator can never be deactivated, not even by
 * two admins deactivating each other at the same moment.
 */
final class AccountActions
{
    public static function resetPassword(): Action
    {
        return Action::make('resetPassword')
            ->label('Reset password')
            ->icon(Heroicon::OutlinedKey)
            ->color('gray')
            ->authorize('update')
            ->visible(fn (User $record): bool => ! self::isCurrentUser($record))
            ->modalHeading(fn (User $record): string => "Reset password for {$record->name}")
            ->modalDescription('The site sends no email: tell the person the new password yourself. Their other sign-ins end.')
            ->modalSubmitActionLabel('Set new password')
            ->modalWidth('md')
            ->schema([
                TextInput::make('password')
                    ->label('New password')
                    ->helperText('At least 10 characters, with letters and numbers.')
                    ->password()
                    ->revealable()
                    ->autocomplete('new-password')
                    ->required()
                    ->rule(PasswordRule::make())
                    ->same('passwordConfirmation')
                    ->validationMessages(['same' => 'The two passwords do not match.']),
                TextInput::make('passwordConfirmation')
                    ->label('Confirm new password')
                    ->password()
                    ->revealable()
                    ->autocomplete('new-password')
                    ->required()
                    ->dehydrated(false),
            ])
            ->action(function (User $record, array $data): void {
                if (self::refuseOwnAccount($record, 'Use "Profile" in the user menu to change your own password.')) {
                    return;
                }

                // The model's "hashed" cast hashes it. A changed hash also ends the person's other sessions (AuthenticateSession).
                $record->update(['password' => $data['password']]);

                Notification::make()->success()
                    ->title('Password changed')
                    ->body("Give {$record->name} the new password yourself.")
                    ->send();
            });
    }

    public static function deactivate(): Action
    {
        return Action::make('deactivate')
            ->label('Deactivate')
            ->icon(Heroicon::OutlinedNoSymbol)
            ->color('danger')
            ->authorize('update')
            ->visible(fn (User $record): bool => $record->is_active && ! self::isCurrentUser($record))
            ->requiresConfirmation()
            ->modalHeading(fn (User $record): string => "Deactivate {$record->name}?")
            ->modalDescription('They can no longer sign in and are locked out at once. Their stories stay as they are. You can activate the account again at any time.')
            ->modalSubmitActionLabel('Deactivate')
            ->action(function (User $record): void {
                if (self::refuseOwnAccount($record, 'You cannot deactivate your own account.')) {
                    return;
                }

                if (! TeamGuard::keepingAnAdministrator([$record->getKey()], fn () => $record->update(['is_active' => false]))) {
                    TeamGuard::refuseLastAdministrator();

                    return;
                }

                Notification::make()->success()->title("{$record->name} was deactivated.")->send();
            });
    }

    public static function activate(): Action
    {
        return Action::make('activate')
            ->label('Activate')
            ->icon(Heroicon::OutlinedCheckCircle)
            ->color('success')
            ->authorize('update')
            ->visible(fn (User $record): bool => ! $record->is_active)
            ->requiresConfirmation()
            ->modalHeading(fn (User $record): string => "Activate {$record->name}?")
            ->modalDescription('They can sign in again with their current password.')
            ->modalSubmitActionLabel('Activate')
            ->action(function (User $record): void {
                $record->update(['is_active' => true]);

                Notification::make()->success()->title("{$record->name} was activated.")->send();
            });
    }

    public static function deactivateSelected(): BulkAction
    {
        return BulkAction::make('deactivateSelected')
            ->label('Deactivate selected')
            ->icon(Heroicon::OutlinedNoSymbol)
            ->color('danger')
            ->authorizeIndividualRecords('update')
            ->requiresConfirmation()
            ->modalHeading('Deactivate the selected accounts?')
            ->modalDescription('They can no longer sign in. Your own account is never touched, even when it is selected.')
            ->deselectRecordsAfterCompletion()
            ->action(fn (Collection $records) => self::setActive($records, false));
    }

    public static function activateSelected(): BulkAction
    {
        return BulkAction::make('activateSelected')
            ->label('Activate selected')
            ->icon(Heroicon::OutlinedCheckCircle)
            ->color('success')
            ->authorizeIndividualRecords('update')
            ->requiresConfirmation()
            ->modalHeading('Activate the selected accounts?')
            ->deselectRecordsAfterCompletion()
            ->action(fn (Collection $records) => self::setActive($records, true));
    }

    /** @param  Collection<int, User>  $records */
    private static function setActive(Collection $records, bool $active): void
    {
        $others = $records->reject(fn (User $user): bool => self::isCurrentUser($user));
        $skipped = $records->count() - $others->count();

        if ($others->isEmpty()) {
            Notification::make()->danger()->title('Nothing was changed')->body('Your own account cannot be changed this way.')->send();

            return;
        }

        // One statement, not one per row: a role change or password is never part of this move.
        $switch = fn () => User::query()->whereKey($others->modelKeys())->update(['is_active' => $active]);

        // Activating can never remove an administrator; deactivating is all-or-nothing under the guard.
        if ($active) {
            $switch();
        } elseif (! TeamGuard::keepingAnAdministrator($others->modelKeys(), $switch)) {
            TeamGuard::refuseLastAdministrator();

            return;
        }

        $changed = $others->count();
        $what = $active ? 'activated' : 'deactivated';

        Notification::make()->success()
            ->title($changed === 1 ? "1 account {$what}." : "{$changed} accounts {$what}.")
            ->body($skipped > 0 ? 'Your own account was left as it is.' : null)
            ->send();
    }

    private static function isCurrentUser(User $user): bool
    {
        return $user->is(auth()->user());
    }

    private static function refuseOwnAccount(User $user, string $message): bool
    {
        if (! self::isCurrentUser($user)) {
            return false;
        }

        Notification::make()->danger()->title('Nothing was changed')->body($message)->send();

        return true;
    }
}
