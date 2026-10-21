<?php

namespace App\Filament\Resources\Users\Support;

use App\Enums\Permission;
use App\Enums\Role;
use App\Models\User;
use Closure;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The two rules the Team area enforces on the server, whatever the buttons show:
 *
 * 1. The byline account is not a person. The launch stories are credited to desk@sisi.news and
 *    nobody should ever sign in as it, so the Team area does not list it, cannot open it and cannot
 *    activate it, change its role or set its password. Because it cannot be reached, those moves have
 *    no code path to guard; what remains is keeping anybody else from taking over the address.
 * 2. There is always at least one active administrator, meaning an active account with the "manage the team"
 *    permission (an admin's bundle has it; it can also be ticked or unticked per person). Without one nobody can
 *    manage staff again, and the only way back is a shell on the server.
 */
final class TeamGuard
{
    /** Same address as LaunchContentImporter::BYLINE_EMAIL (a test keeps the two equal). */
    public const BYLINE_EMAIL = 'desk@sisi.news';

    public static function isReservedEmail(?string $email): bool
    {
        return strtolower(trim((string) $email)) === self::BYLINE_EMAIL;
    }

    public static function isProtected(User $user): bool
    {
        return self::isReservedEmail($user->email);
    }

    /**
     * @param  Builder<User>  $query
     * @return Builder<User>
     */
    public static function withoutProtectedAccounts(Builder $query): Builder
    {
        // LOWER() because SQLite compares case-sensitively while MySQL/MariaDB do not.
        return $query->whereRaw('LOWER(email) <> ?', [self::BYLINE_EMAIL]);
    }

    /**
     * Runs $change only if an active administrator is still left once it has run.
     *
     * Every administrator row is locked (SELECT ... FOR UPDATE) for the length of the transaction, so
     * two admins who deactivate each other at the same moment are handled one after the other: the
     * second one sees the first one's result and is refused. A check done before the transaction would
     * let both through.
     *
     * @param  list<int|string>  $leaving  accounts that stop being an active administrator when $change runs
     * @return bool false when nothing was changed because it would have left no active administrator
     */
    public static function keepingAnAdministrator(array $leaving, Closure $change): bool
    {
        try {
            return DB::transaction(function () use ($leaving, $change): bool {
                $active = self::activeAdministratorIds();

                $leaving = array_map('intval', $leaving);
                $removed = array_intersect($active, $leaving);

                if ($removed !== [] && array_diff($active, $leaving) === []) {
                    return false;
                }

                $change();

                // A role or permission change can take "manage the team" away without anyone being listed as
                // leaving, so the result is checked too, and undone (the transaction rolls back) when nobody is left.
                if (self::activeAdministratorIds() === []) {
                    throw new LastAdministratorWouldLeave;
                }

                return true;
            });
        } catch (LastAdministratorWouldLeave) {
            return false;
        }
    }

    /** @return list<int> active accounts that can manage the team, read from the locked rows */
    private static function activeAdministratorIds(): array
    {
        return self::lockedAdministrators()
            ->get(['id', 'role', 'is_active', 'permissions_granted', 'permissions_revoked'])
            ->filter(fn (User $account): bool => $account->hasPermission(Permission::ManageTeam))
            ->map(fn (User $account): int => (int) $account->getKey())
            ->values()
            ->all();
    }

    /**
     * Every row that could hold "manage the team" (the admin role, or the permission ticked by hand), locked for the
     * surrounding transaction; whether it still does (not unticked, active) is judged per row. The byline account
     * never counts, even if it somehow holds the admin role: nobody is meant to sign in as it.
     *
     * @return Builder<User>
     */
    public static function lockedAdministrators(): Builder
    {
        return self::withoutProtectedAccounts(User::query())
            ->where(fn (Builder $query): Builder => $query
                ->where('role', Role::Admin->value)
                // The JSON list as stored text: portable across SQLite and MariaDB, and the team is small.
                ->orWhere('permissions_granted', 'like', '%"'.Permission::ManageTeam->value.'"%'))
            ->lockForUpdate();
    }

    public static function refuseLastAdministrator(): void
    {
        Notification::make()->danger()
            ->title(__('Nothing was changed'))
            ->body(__('At least one active administrator must remain. Make another account an active administrator first.'))
            ->send();
    }
}
