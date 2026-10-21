<?php

namespace App\Filament\Support;

use Filament\Support\Contracts\HasLabel;

/**
 * The sidebar groups, in order. An enum rather than plain strings so the labels are translated when the sidebar is
 * drawn (after UseStaffLanguage has picked the language), not when the panel is configured.
 */
enum AdminNavigationGroup: string implements HasLabel
{
    case Newsroom = 'newsroom';
    case Taxonomy = 'taxonomy';
    case Audience = 'audience';
    case Site = 'site';

    public function getLabel(): string
    {
        return match ($this) {
            self::Newsroom => __('Newsroom'),
            self::Taxonomy => __('Taxonomy'),
            self::Audience => __('Audience'),
            self::Site => __('Site'),
        };
    }
}
