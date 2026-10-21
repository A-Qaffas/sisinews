<?php

namespace App\Filament\Pages\Auth;

use Filament\Auth\Pages\Login as BaseLogin;
use Filament\Schemas\Components\Component;
use Illuminate\Contracts\Support\Htmlable;

class Login extends BaseLogin
{
    /** An address types left-to-right even when the panel is Arabic. */
    protected function getEmailFormComponent(): Component
    {
        return parent::getEmailFormComponent()->extraInputAttributes(['dir' => 'ltr']);
    }

    public function getHeading(): string|Htmlable|null
    {
        return __('Newsroom sign in');
    }

    public function getSubheading(): string|Htmlable|null
    {
        return __('Staff accounts only. Ask an administrator if you need access.');
    }
}
