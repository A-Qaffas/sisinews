<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\ArticleTranslation;
use App\Models\User;

/**
 * WHO may act on a language version. Whether the move is legal for the current
 * status is NOT decided here; EditorialWorkflow / Transition own that.
 */
class ArticleTranslationPolicy
{
    /** Deactivated staff are locked out of everything, before any ability runs. */
    public function before(User $user): ?bool
    {
        return $user->is_active ? null : false;
    }

    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, ArticleTranslation $t): bool
    {
        return $user->hasPermission(Permission::EditAllStories) || $this->owns($user, $t);
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, ArticleTranslation $t): bool
    {
        if ($user->hasPermission(Permission::EditAllStories)) {
            return true;
        }

        return $this->owns($user, $t) && $t->status->isAuthorEditable() && $this->mayWriteInSection($user, $t);
    }

    public function submit(User $user, ArticleTranslation $t): bool
    {
        return $user->hasPermission(Permission::EditAllStories) || ($this->owns($user, $t) && $this->mayWriteInSection($user, $t));
    }

    public function approve(User $user, ArticleTranslation $t): bool
    {
        return $user->hasPermission(Permission::PublishStories);
    }

    public function requestChanges(User $user, ArticleTranslation $t): bool
    {
        return $user->hasPermission(Permission::PublishStories);
    }

    public function archive(User $user, ArticleTranslation $t): bool
    {
        return $user->hasPermission(Permission::PublishStories);
    }

    public function reopen(User $user, ArticleTranslation $t): bool
    {
        return $user->hasPermission(Permission::PublishStories);
    }

    public function delete(User $user, ArticleTranslation $t): bool
    {
        return $user->hasPermission(Permission::DeleteStories);
    }

    private function owns(User $user, ArticleTranslation $t): bool
    {
        return $t->article->author_id === $user->getKey();
    }

    /** A journalist works only in the sections an admin assigned; one whose section was removed can read the story, not change or submit it. */
    private function mayWriteInSection(User $user, ArticleTranslation $t): bool
    {
        return $user->canWriteIn($t->article->category_id);
    }
}
