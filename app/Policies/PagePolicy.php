<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Page;
use App\Models\User;

/**
 * Static pages carry the site's policies and trust statements: editors and administrators write them,
 * journalists never see them. Removing a page is an administrator's call, and the five core pages
 * (About, Standards, Corrections, Privacy, Terms) cannot be deleted at all: unpublish a language instead.
 * Bulk deletion is not offered.
 */
class PagePolicy
{
    public function before(User $user): ?bool
    {
        return $user->is_active ? null : false;
    }

    public function viewAny(User $user): bool
    {
        return $user->hasPermission(Permission::ManagePages);
    }

    public function view(User $user, Page $page): bool
    {
        return $user->hasPermission(Permission::ManagePages);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission(Permission::ManagePages);
    }

    public function update(User $user, Page $page): bool
    {
        return $user->hasPermission(Permission::ManagePages);
    }

    public function delete(User $user, Page $page): bool
    {
        return $user->hasPermission(Permission::DeletePages) && ! $page->isCore();
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
