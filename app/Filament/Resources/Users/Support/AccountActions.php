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
            ->label(__('Reset password'))
            ->icon(Heroicon::OutlinedKey)
            ->color('gray')
            ->authorize('update')
            ->visible(fn (User $record): bool => ! self::isCurrentUser($record))
            ->modalHeading(fn (User $record): string => __('Reset password for :name', ['name' => $record->name]))
            ->modalDescription(__('The site sends no email: tell the person the new password yourself. Their other sign-ins end.'))
            ->modalSubmitActionLabel(__('Set new password'))
            ->modalWidth('md')
            ->schema([
                TextInput::make('password')
                    ->label(__('New password'))
                    ->helperText(__('At least 10 characters, with letters and numbers.'))
                    ->password()
                    ->revealable()
                    ->autocomplete('new-password')
                    ->required()
                    ->rule(PasswordRule::make())
                    ->same('passwordConfirmation')
                    ->validationMessages(['same' => __('The two passwords do not match.')]),
                TextInput::make('passwordConfirmation')
                    ->label(__('Confirm new password'))
                    ->password()
                    ->revealable()
                    ->autocomplete('new-password')
                    ->required()
                    ->dehydrated(false),
            ])
            ->action(function (User $record, array $data): void {
                if (self::refuseOwnAccount($record, __('Use "Profile" in the user menu to change your own password.'))) {
                    return;
                }

                // The model's "hashed" cast hashes it. A changed hash also ends the person's other sessions (AuthenticateSession).
                $record->update(['password' => $data['password']]);

                Notification::make()->success()
                    ->title(__('Password changed'))
                    ->body(__('Give :name the new password yourself.', ['name' => $record->name]))
                    ->send();
            });
    }

    public static function deactivate(): Action
    {
        return Action::make('deactivate')
            ->label(__('Deactivate'))
            ->icon(Heroicon::OutlinedNoSymbol)
            ->color('danger')
            ->authorize('update')
            ->visible(fn (User $record): bool => $record->is_active && ! self::isCurrentUser($record))
            ->requiresConfirmation()
            ->modalHeading(fn (User $record): string => __('Deactivate :name?', ['name' => $record->name]))
            ->modalDescription(__('They can no longer sign in and are locked out at once. Their stories stay as they are. You can activate the account again at any time.'))
            ->modalSubmitActionLabel(__('Deactivate'))
            ->action(function (User $record): void {
                if (self::refuseOwnAccount($record, __('You cannot deactivate your own account.'))) {
                    return;
                }

                if (! TeamGuard::keepingAnAdministrator([$record->getKey()], fn () => $record->update(['is_active' => false]))) {
                    TeamGuard::refuseLastAdministrator();

                    return;
                }

                Notification::make()->success()->title(__(':name was deactivated.', ['name' => $record->name]))->send();
            });
    }

    public static function activate(): Action
    {
        return Action::make('activate')
            ->label(__('Activate'))
            ->icon(Heroicon::OutlinedCheckCircle)
            ->color('success')
            ->authorize('update')
            ->visible(fn (User $record): bool => ! $record->is_active)
            ->requiresConfirmation()
            ->modalHeading(fn (User $record): string => __('Activate :name?', ['name' => $record->name]))
            ->modalDescription(__('They can sign in again with their current password.'))
            ->modalSubmitActionLabel(__('Activate'))
            ->action(function (User $record): void {
                $record->update(['is_active' => true]);

                Notification::make()->success()->title(__(':name was activated.', ['name' => $record->name]))->send();
            });
    }

    public static function deactivateSelected(): BulkAction
    {
        return BulkAction::make('deactivateSelected')
            ->label(__('Deactivate selected'))
            ->icon(Heroicon::OutlinedNoSymbol)
            ->color('danger')
            ->authorizeIndividualRecords('update')
            ->requiresConfirmation()
            ->modalHeading(__('Deactivate the selected accounts?'))
            ->modalDescription(__('They can no longer sign in. Your own account is never touched, even when it is selected.'))
            ->deselectRecordsAfterCompletion()
            ->action(fn (Collection $records) => self::setActive($records, false));
    }

    public static function activateSelected(): BulkAction
    {
        return BulkAction::make('activateSelected')
            ->label(__('Activate selected'))
            ->icon(Heroicon::OutlinedCheckCircle)
            ->color('success')
            ->authorizeIndividualRecords('update')
            ->requiresConfirmation()
            ->modalHeading(__('Activate the selected accounts?'))
            ->deselectRecordsAfterCompletion()
            ->action(fn (Collection $records) => self::setActive($records, true));
    }

    /** @param  Collection<int, User>  $records */
    private static function setActive(Collection $records, bool $active): void
    {
        $others = $records->reject(fn (User $user): bool => self::isCurrentUser($user));
        $skipped = $records->count() - $others->count();

        if ($others->isEmpty()) {
            Notification::make()->danger()->title(__('Nothing was changed'))->body(__('Your own account cannot be changed this way.'))->send();

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

        Notification::make()->success()
            ->title($active
                ? trans_choice(':count account activated.|:count accounts activated.', $changed)
                : trans_choice(':count account deactivated.|:count accounts deactivated.', $changed))
            ->body($skipped > 0 ? __('Your own account was left as it is.') : null)
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

        Notification::make()->danger()->title(__('Nothing was changed'))->body($message)->send();

        return true;
    }
}
