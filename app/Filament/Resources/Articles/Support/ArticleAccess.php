<?php

namespace App\Filament\Resources\Articles\Support;

use App\Enums\Locale;
use App\Enums\Permission;
use App\Filament\Support\TaxonomyLabels;
use App\Models\Article;
use App\Models\ArticleTranslation;
use App\Models\User;

/**
 * Answers "may this user edit this part of the story?" from the policies, so the
 * form (what is disabled) and the writer (what is saved) can never disagree.
 */
final class ArticleAccess
{
    /** The shared shell: category, hero image, flags, tags, author. A null article means "creating". */
    public static function canEditShell(User $user, ?Article $article): bool
    {
        return $article === null || $user->can('update', $article);
    }

    public static function canEditTranslation(User $user, ?Article $article, Locale $locale): bool
    {
        if ($article === null) {
            return true;
        }

        $translation = self::translation($article, $locale);
        if ($translation !== null) {
            return $user->can('update', $translation);
        }

        // No version in this language yet: whoever may edit everyone's stories, and the story's own author, may add
        // one; the author only while the story's section is still one of theirs (the same rule as editing a version).
        return $user->hasPermission(Permission::EditAllStories)
            || ($article->author_id === $user->getKey() && $user->canWriteIn($article->category_id));
    }

    public static function canEditAnything(User $user, ?Article $article): bool
    {
        return self::canEditShell($user, $article)
            || collect(Locale::cases())->contains(fn (Locale $l): bool => self::canEditTranslation($user, $article, $l));
    }

    /**
     * The section's name when $user is a journalist looking at their OWN story in a section an admin has taken away
     * from them (they may read it, not change it); null otherwise. The form says so, instead of a bare locked page.
     */
    public static function lostSectionName(User $user, ?Article $article): ?string
    {
        if ($article === null || $user->hasPermission(Permission::EditAllStories) || $article->author_id !== $user->getKey() || $user->canWriteIn($article->category_id)) {
            return null;
        }

        return $article->category?->nameIn(Locale::current());
    }

    /** The loaded translation with its article attached, which the policy reads (saves a query per check). */
    public static function translation(Article $article, Locale $locale): ?ArticleTranslation
    {
        $translation = $article->translation($locale);
        $translation?->setRelation('article', $article);

        return $translation;
    }

    /**
     * The sections the category select offers: all of them for a reviewer, only the assigned ones for a
     * journalist. The story's current section stays listed so a story that can no longer be edited still
     * shows where it sits; the select is disabled then, and ArticleWriter re-checks every section on save.
     *
     * @return array<int, string> category id => label
     */
    public static function categoryOptions(User $user, ?Article $article): array
    {
        $options = TaxonomyLabels::categoryOptions();

        if ($user->hasPermission(Permission::WriteInAllSections)) {
            return $options;
        }

        $allowed = $user->allowedCategories()->pluck('categories.id')->all();
        if ($article !== null) {
            $allowed[] = $article->category_id;
        }

        return array_intersect_key($options, array_flip($allowed));
    }
}
