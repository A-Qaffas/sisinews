<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\User;

/** The site settings (masthead names...) are changed by active administrators only. */
class SiteSettingPolicy
{
    public function before(User $user): ?bool
    {
        return $user->is_active ? null : false;
    }

    public function manage(User $user): bool
    {
        return $user->hasPermission(Permission::ManageSettings);
    }
}
