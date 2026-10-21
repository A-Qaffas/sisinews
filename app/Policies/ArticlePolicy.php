<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Article;
use App\Models\User;

/** WHO may act on the language-neutral shell (category, hero image, flags). */
class ArticlePolicy
{
    public function before(User $user): ?bool
    {
        return $user->is_active ? null : false;
    }

    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Article $article): bool
    {
        return $user->hasPermission(Permission::EditAllStories) || $article->author_id === $user->getKey();
    }

    /** "Write stories", and either "write in every section" or at least one section an admin assigned. */
    public function create(User $user): bool
    {
        return $user->hasPermission(Permission::WriteStories)
            && ($user->hasPermission(Permission::WriteInAllSections) || $user->allowedCategories()->exists());
    }

    /**
     * Authors may change the shared shell only while EVERY existing language
     * version is still theirs to edit (draft / changes requested); once any
     * version is in review or live, only editors/admins touch it. A journalist
     * also needs the story's section: if an admin took it away they can still
     * read the story (view) but no longer change it.
     */
    public function update(User $user, Article $article): bool
    {
        if ($user->hasPermission(Permission::EditAllStories)) {
            return true;
        }
        if ($article->author_id !== $user->getKey()) {
            return false;
        }
        if (! $article->translations->every(fn ($t) => $t->status->isAuthorEditable())) {
            return false;
        }

        return $user->canWriteIn($article->category_id);
    }

    public function delete(User $user, Article $article): bool
    {
        return $user->hasPermission(Permission::DeleteStories);
    }

    public function restore(User $user, Article $article): bool
    {
        return $user->hasPermission(Permission::PurgeStories);
    }

    public function forceDelete(User $user, Article $article): bool
    {
        return $user->hasPermission(Permission::PurgeStories);
    }
}
