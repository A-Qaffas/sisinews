<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\User;

/**
 * Staff accounts are managed by administrators only. Accounts are deactivated, never
 * deleted, because articles keep their author; so delete is denied for everyone.
 */
class UserPolicy
{
    public function before(User $user): ?bool
    {
        return $user->is_active ? null : false;
    }

    public function viewAny(User $user): bool
    {
        return $user->hasPermission(Permission::ManageTeam);
    }

    public function view(User $user, User $account): bool
    {
        return $user->hasPermission(Permission::ManageTeam);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission(Permission::ManageTeam);
    }

    public function update(User $user, User $account): bool
    {
        return $user->hasPermission(Permission::ManageTeam);
    }

    public function delete(User $user, User $account): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
