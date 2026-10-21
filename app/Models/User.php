<?php

namespace App\Models;

use App\Enums\Locale;
use App\Enums\Permission;
use App\Enums\Role;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

// Staff only. v1 has no public/reader accounts, so there is no registration flow:
// an admin creates every account. `role` and `is_active` are mass-assignable ONLY
// because the admin-only user resource needs them; never expose a public form.
#[Fillable(['name', 'name_ar', 'email', 'password', 'role', 'is_active', 'locale'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements FilamentUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'role' => Role::class,
            'is_active' => 'boolean',
            'locale' => Locale::class,
            'permissions_granted' => 'array',
            'permissions_revoked' => 'array',
        ];
    }

    /** The language this person works in on /admin: their own choice, else the panel default (Arabic). */
    public function adminLocale(): Locale
    {
        return $this->locale ?? Locale::tryFrom((string) config('site.admin_locale')) ?? Locale::default();
    }

    /** Every account is staff; the only gate is the admin's "active" switch. */
    public function canAccessPanel(Panel $panel): bool
    {
        return $this->is_active;
    }

    public function articles(): HasMany
    {
        return $this->hasMany(Article::class, 'author_id');
    }

    /** Sections a journalist may write in (ignored for editors and admins). */
    public function allowedCategories(): BelongsToMany
    {
        return $this->belongsToMany(Category::class);
    }

    /**
     * May this person start or change a story in $category? With "write in every section", anywhere; otherwise only
     * in the sections an admin assigned; never without "write stories", and an inactive account nowhere.
     */
    public function canWriteIn(Category|int|null $category): bool
    {
        if (! $this->is_active || $category === null || ! $this->hasPermission(Permission::WriteStories)) {
            return false;
        }
        if ($this->hasPermission(Permission::WriteInAllSections)) {
            return true;
        }

        $id = $category instanceof Category ? $category->getKey() : $category;

        return $this->allowedCategories()->whereKey($id)->exists();
    }

    /**
     * What this person may do: their role's bundle, plus what an admin ticked for them, minus what was unticked.
     * Unknown values (a permission since removed from the code) are ignored. An inactive account has none.
     *
     * @return list<Permission>
     */
    public function permissions(): array
    {
        return $this->is_active ? $this->configuredPermissions() : [];
    }

    /**
     * The permissions set for this account whether or not it is active (the Team form shows them on a deactivated
     * account too, so switching it back on restores exactly what it had).
     *
     * @return list<Permission>
     */
    public function configuredPermissions(): array
    {
        $granted = self::permissionList($this->permissions_granted);
        $revoked = self::permissionList($this->permissions_revoked);

        return array_values(array_filter(
            Permission::cases(),
            fn (Permission $p): bool => (in_array($p, $this->role->permissions(), true) || in_array($p, $granted, true))
                && ! in_array($p, $revoked, true),
        ));
    }

    public function hasPermission(Permission $permission): bool
    {
        return in_array($permission, $this->permissions(), true);
    }

    /** True when an admin changed this person's bundle (shown as "custom" in the Team list). */
    public function hasCustomPermissions(): bool
    {
        return self::permissionList($this->permissions_granted) !== [] || self::permissionList($this->permissions_revoked) !== [];
    }

    /**
     * Stores exactly $chosen as this person's permissions, as the difference from their role's bundle, so a later
     * change to the bundle still reaches them for everything nobody changed by hand. Not saved; the caller saves.
     *
     * @param  iterable<Permission|string>  $chosen
     */
    public function choosePermissions(iterable $chosen): void
    {
        $chosen = self::permissionList($chosen);
        $bundle = $this->role->permissions();

        $granted = array_values(array_filter($chosen, fn (Permission $p): bool => ! in_array($p, $bundle, true)));
        $revoked = array_values(array_filter($bundle, fn (Permission $p): bool => ! in_array($p, $chosen, true)));

        $this->forceFill([
            'permissions_granted' => $granted === [] ? null : array_map(fn (Permission $p): string => $p->value, $granted),
            'permissions_revoked' => $revoked === [] ? null : array_map(fn (Permission $p): string => $p->value, $revoked),
        ]);
    }

    /**
     * @param  iterable<Permission|string>|null  $values
     * @return list<Permission>
     */
    private static function permissionList(?iterable $values): array
    {
        $list = [];
        foreach ($values ?? [] as $value) {
            $permission = $value instanceof Permission ? $value : Permission::tryFrom((string) $value);
            if ($permission !== null && ! in_array($permission, $list, true)) {
                $list[] = $permission;
            }
        }

        return $list;
    }

    /** Byline name in the reader's language, falling back to the Latin name. */
    public function displayName(Locale $locale): string
    {
        return $locale === Locale::Ar && filled($this->name_ar) ? $this->name_ar : $this->name;
    }
}
