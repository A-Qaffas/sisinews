<?php

namespace App\Enums;

enum LiveStatus: string
{
    case Scheduled = 'scheduled';
    case Live = 'live';
    case Ended = 'ended';

    public function label(): string
    {
        return match ($this) {
            self::Scheduled => __('Scheduled'),
            self::Live => __('Live now'),
            self::Ended => __('Ended'),
        };
    }
}
