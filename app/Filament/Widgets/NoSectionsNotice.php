<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\Articles\Support\NoSectionsNotice as Notice;
use Filament\Widgets\Widget;

/** First thing a journalist without any section sees on the dashboard: why there is no "New article" button. */
class NoSectionsNotice extends Widget
{
    // A notice is its own message: it must be in the first paint, not a placeholder that fills in a moment later.
    protected static bool $isLazy = false;

    protected static ?int $sort = -10;

    protected int|string|array $columnSpan = 'full';

    protected string $view = 'filament.widgets.no-sections-notice';

    public static function canView(): bool
    {
        return Notice::applies(auth()->user());
    }
}
