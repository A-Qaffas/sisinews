<?php

namespace App\Filament\Support;

use Filament\AvatarProviders\Contracts\AvatarProvider;
use Filament\Facades\Filament;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;

/** Inline-SVG initials avatar: no request to a third-party avatar service. */
final class InitialsAvatarProvider implements AvatarProvider
{
    public function get(Model|Authenticatable $record): string
    {
        $initials = collect(preg_split('/\s+/u', trim(Filament::getNameForDefaultAvatar($record))))
            ->map(fn (string $word): string => mb_substr(preg_replace('/^[^\p{L}\p{N}]+/u', '', $word), 0, 1))
            ->filter()
            ->take(2)
            ->implode('');

        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64"><rect width="64" height="64" fill="#172A3A"/>'
            .'<text x="32" y="32" dy=".35em" text-anchor="middle" fill="#F7F2E9" font-family="sans-serif" font-size="26">'
            .e(mb_strtoupper($initials)).'</text></svg>';

        return 'data:image/svg+xml;base64,'.base64_encode($svg);
    }
}
