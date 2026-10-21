<?php

namespace App\Filament\Resources\Users\Support;

use App\Enums\Permission;
use App\Enums\Role;
use App\Models\User;
use Filament\Forms\Components\CheckboxList;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;

/**
 * The Team form's permission checkboxes: one box per group (stories, sections and tags, site, administration).
 * Choosing a role ticks that role's ready-made bundle; the admin can then tick or untick single permissions for this
 * person. What is saved is the difference from the bundle (User::choosePermissions()).
 *
 * Your own permissions are shown but locked, like your own role: nobody can widen or cut their own access.
 */
final class PermissionFields
{
    private const PREFIX = 'permissions_';

    public static function section(): Section
    {
        return Section::make(__('Permissions'))
            ->description(__('The role ticks its usual permissions. Tick or untick any of them for this person only.'))
            ->columns(2)
            ->schema(array_map(
                fn (string $group): CheckboxList => CheckboxList::make(self::PREFIX.$group)
                    ->label(Permission::groupLabel($group))
                    ->options(collect(Permission::inGroup($group))->mapWithKeys(fn (Permission $p): array => [$p->value => $p->label()])->all())
                    ->descriptions(collect(Permission::inGroup($group))->mapWithKeys(fn (Permission $p): array => [$p->value => $p->description()])->filter()->all())
                    ->default(self::bundle(Role::Journalist, $group))
                    // Live, so the section picker appears or goes as "write in every section" changes.
                    ->live()
                    ->disabled(fn (?User $record): bool => $record !== null && $record->is(auth()->user())),
                Permission::GROUPS,
            ));
    }

    /** After the role select changes: tick exactly the new role's bundle. */
    public static function resetTo(Set $set, mixed $role): void
    {
        $role = $role instanceof Role ? $role : Role::tryFrom((string) $role);
        if ($role === null) {
            return;
        }

        foreach (Permission::GROUPS as $group) {
            $set(self::PREFIX.$group, self::bundle($role, $group));
        }
    }

    /** @return array<string, list<string>> form state for an existing account */
    public static function fill(User $account): array
    {
        $chosen = $account->configuredPermissions();
        $state = [];

        foreach (Permission::GROUPS as $group) {
            $state[self::PREFIX.$group] = array_values(array_map(
                fn (Permission $p): string => $p->value,
                array_filter(Permission::inGroup($group), fn (Permission $p): bool => in_array($p, $chosen, true)),
            ));
        }

        return $state;
    }

    /**
     * Takes the checkboxes out of the submitted data (they are not columns). Null when none was submitted: a locked
     * (own account) form sends nothing, and then nothing changes.
     *
     * @param  array<string, mixed>  $data
     * @return list<string>|null
     */
    public static function pull(array &$data): ?array
    {
        $chosen = null;

        foreach (Permission::GROUPS as $group) {
            if (array_key_exists(self::PREFIX.$group, $data)) {
                $chosen = [...($chosen ?? []), ...array_map('strval', (array) $data[self::PREFIX.$group])];
                unset($data[self::PREFIX.$group]);
            }
        }

        return $chosen;
    }

    /** Is $permission ticked in the form right now? */
    public static function ticked(Get $get, Permission $permission): bool
    {
        return in_array($permission->value, (array) $get(self::PREFIX.$permission->group()), true);
    }

    /** @return list<string> */
    private static function bundle(Role $role, string $group): array
    {
        return array_values(array_map(
            fn (Permission $p): string => $p->value,
            array_filter(Permission::inGroup($group), fn (Permission $p): bool => in_array($p, $role->permissions(), true)),
        ));
    }
}
