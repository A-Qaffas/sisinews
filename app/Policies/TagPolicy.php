<?php

namespace App\Policies;

use App\Models\Tag;
use App\Models\User;

/**
 * Every staff member may read the tags; only reviewers (editor/admin) rename or delete them.
 * Authors can still add a tag while writing a story (the article form's "create" option).
 */
class TagPolicy
{
    public function before(User $user): ?bool
    {
        return $user->is_active ? null : false;
    }

    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Tag $tag): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return $user->isReviewer();
    }

    public function update(User $user, Tag $tag): bool
    {
        return $user->isReviewer();
    }

    public function delete(User $user, Tag $tag): bool
    {
        return $user->isReviewer();
    }

    public function deleteAny(User $user): bool
    {
        return $user->isReviewer();
    }
}
