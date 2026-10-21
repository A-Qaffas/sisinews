<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Category;
use App\Models\User;

/** Every staff member may read the sections; only reviewers (editor/admin) manage them. */
class CategoryPolicy
{
    public function before(User $user): ?bool
    {
        return $user->is_active ? null : false;
    }

    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Category $category): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return $user->hasPermission(Permission::ManageSections);
    }

    public function update(User $user, Category $category): bool
    {
        return $user->hasPermission(Permission::ManageSections);
    }

    public function delete(User $user, Category $category): bool
    {
        return $user->hasPermission(Permission::ManageSections);
    }

    public function deleteAny(User $user): bool
    {
        return $user->hasPermission(Permission::ManageSections);
    }

    public function reorder(User $user): bool
    {
        return $user->hasPermission(Permission::ManageSections);
    }
}
