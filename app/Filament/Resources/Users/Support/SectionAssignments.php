<?php

namespace App\Filament\Resources\Users\Support;

use App\Enums\Locale;
use App\Enums\Permission;
use App\Enums\Role;
use App\Models\Category;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * Which sections a journalist may write in (the category_user pivot), as the Team area handles it.
 *
 * Only an administrator may change an assignment: sync() asks UserPolicy itself, so it does not matter
 * which page or payload reached it. Editors-in-chief and admins write in every section, so their pivot
 * rows (kept when someone changes role, so a round trip loses nothing) are never read for them.
 */
final class SectionAssignments
{
    /** Form state key. Not a column of users: the pages take it out of the data before the model is saved. */
    public const STATE = 'section_ids';

    /** Sections named in the list before it says "+N more". */
    private const LISTED = 2;

    /** The select is only for journalists; the form hides it (and so does not submit it) for other roles. */
    public static function applies(mixed $role): bool
    {
        return ($role instanceof Role ? $role : Role::tryFrom((string) $role)) === Role::Journalist;
    }

    /** @return list<int> */
    public static function assignedIds(User $account): array
    {
        return $account->allowedCategories()->pluck('categories.id')->map(fn ($id): int => (int) $id)->all();
    }

    /** @param  array<int|string>  $ids  ids that are not a section are dropped */
    public static function sync(User $account, array $ids, User $by): void
    {
        Gate::forUser($by)->authorize('update', $account);

        $account->allowedCategories()->sync(Category::query()->whereKey($ids)->pluck('id')->all());
    }

    /** "Egypt, Politics +2 more", "None yet", or "All sections" for those who need no assignment. Needs allowedCategories.translations loaded. */
    public static function summary(User $account): string
    {
        if ($account->hasPermission(Permission::WriteInAllSections)) {
            return __('All sections');
        }

        $names = $account->allowedCategories
            ->sortBy([['sort_order', 'asc'], ['id', 'asc']])
            // Section names in the language the panel is shown in.
            ->map(fn (Category $category): string => $category->nameIn(Locale::current()));

        if ($names->isEmpty()) {
            return __('None yet');
        }

        $more = $names->count() - self::LISTED;
        $listed = $names->take(self::LISTED)->implode(app()->getLocale() === Locale::Ar->value ? '، ' : ', ');

        return $more > 0 ? __(':names +:count more', ['names' => $listed, 'count' => $more]) : $listed;
    }

    public static function hasNone(User $account): bool
    {
        return $account->hasPermission(Permission::WriteStories)
            && ! $account->hasPermission(Permission::WriteInAllSections)
            && $account->allowedCategories->isEmpty();
    }
}
