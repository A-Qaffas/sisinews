<?php

namespace App\Filament\Resources\Articles\Support;

use App\Models\Article;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * The plain-language notice for a journalist an admin has not given any section yet. Shown on the
 * Articles list and on the dashboard; "applies" asks the policy, so the notice and the missing
 * "New article" button can never disagree.
 */
final class NoSectionsNotice
{
    public const HEADING = 'You cannot start a story yet';

    public const BODY = 'An admin has not chosen the sections you may write in. Ask an admin to assign at least one section, then come back and start your story. Stories you already wrote stay readable.';

    /** HEADING in the staff member's language (the constants are the English text and the translation keys). */
    public static function heading(): string
    {
        return __(self::HEADING);
    }

    /** BODY in the staff member's language. */
    public static function body(): string
    {
        return __(self::BODY);
    }

    public static function applies(?User $user): bool
    {
        return $user !== null
            && $user->is_active
            && Gate::forUser($user)->denies('create', Article::class);
    }
}
