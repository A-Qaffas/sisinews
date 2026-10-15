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
            self::Scheduled => 'Scheduled',
            self::Live => 'Live now',
            self::Ended => 'Ended',
        };
    }
}
