<?php

namespace App\Models;

use App\Enums\Locale;
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
#[Fillable(['name', 'name_ar', 'email', 'password', 'role', 'is_active'])]
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
        ];
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
     * May this person start or change a story in $category? Reviewers (editors, admins) work in every section;
     * a journalist only in the sections an admin assigned; an inactive account in none.
     */
    public function canWriteIn(Category|int|null $category): bool
    {
        if (! $this->is_active || $category === null) {
            return false;
        }
        if ($this->isReviewer()) {
            return true;
        }

        $id = $category instanceof Category ? $category->getKey() : $category;

        return $this->allowedCategories()->whereKey($id)->exists();
    }

    public function isReviewer(): bool
    {
        return $this->role->isReviewer();
    }

    public function isAdmin(): bool
    {
        return $this->role === Role::Admin;
    }

    /** Byline name in the reader's language, falling back to the Latin name. */
    public function displayName(Locale $locale): string
    {
        return $locale === Locale::Ar && filled($this->name_ar) ? $this->name_ar : $this->name;
    }
}
