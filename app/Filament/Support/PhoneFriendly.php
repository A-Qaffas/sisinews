<?php

namespace App\Filament\Support;

use Filament\Actions\Action;

/**
 * Row buttons that read "Edit" or "Approve & publish" on a desktop shrink to their icon on a phone,
 * so two or three of them fit beside a headline at 390 px instead of pushing the table sideways.
 * The label stays in the page for screen readers; the CSS lives in filament/hooks/admin-styles.blade.php.
 * (Filament's own labeledFrom() only works on filled buttons, and these tables use text links.)
 */
final class PhoneFriendly
{
    public const ICON_ONLY_CLASS = 'sisi-icon-below-md';

    public const HIDDEN_CLASS = 'sisi-hide-below-md';

    /**
     * For a button the row already makes redundant on a phone because the whole row is a link.
     *
     * @template T of Action
     *
     * @param  T  $action
     * @return T
     */
    public static function hideOnPhone(Action $action): Action
    {
        return $action->extraAttributes(['class' => self::HIDDEN_CLASS], merge: true);
    }

    /**
     * @template T of Action
     *
     * @param  T  $action
     * @return T
     */
    public static function iconOnPhone(Action $action): Action
    {
        return $action->extraAttributes(['class' => self::ICON_ONLY_CLASS], merge: true);
    }
}
