<?php

namespace App\Filament\Pages\Auth;

use Filament\Auth\Pages\Login as BaseLogin;
use Illuminate\Contracts\Support\Htmlable;

class Login extends BaseLogin
{
    public function getHeading(): string|Htmlable|null
    {
        return 'Newsroom sign in';
    }

    public function getSubheading(): string|Htmlable|null
    {
        return 'Staff accounts only. Ask an administrator if you need access.';
    }
}
