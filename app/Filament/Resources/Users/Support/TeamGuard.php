<?php

namespace App\Filament\Resources\Users\Support;

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
 * 2. There is always at least one active administrator. Without one nobody can manage staff
 *    again, and the only way back is a shell on the server.
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
        return DB::transaction(function () use ($leaving, $change): bool {
            $active = self::lockedAdministrators()
                ->get(['id', 'is_active'])
                ->filter(fn (User $admin): bool => $admin->is_active)
                ->modelKeys();

            $leaving = array_map('intval', $leaving);
            $removed = array_intersect($active, $leaving);

            if ($removed !== [] && array_diff($active, $leaving) === []) {
                return false;
            }

            $change();

            return true;
        });
    }

    /**
     * Every administrator row, locked for the surrounding transaction. The byline account never counts,
     * even if it somehow holds the admin role: nobody is meant to sign in as it.
     *
     * @return Builder<User>
     */
    public static function lockedAdministrators(): Builder
    {
        return self::withoutProtectedAccounts(User::query())
            ->where('role', Role::Admin->value)
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
