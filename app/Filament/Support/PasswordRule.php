<?php

namespace App\Filament\Support;

use Illuminate\Validation\Rules\Password;

/** One password policy for every place a staff password is set. */
final class PasswordRule
{
    public static function make(): Password
    {
        return Password::min(10)->letters()->numbers();
    }
}
