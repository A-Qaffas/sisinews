<?php

namespace App\Filament\Support;

use App\Editorial\InvalidTransition;
use Closure;
use Filament\Notifications\Notification;
use Illuminate\Auth\Access\AuthorizationException;

/** Turns a workflow refusal into a friendly notification instead of an error page. */
final class WorkflowOutcome
{
    /**
     * @param  Closure(): mixed  $move  a call into EditorialWorkflow
     * @param  string  $successTitle  already translated
     * @return bool whether the move happened
     */
    public static function run(Closure $move, string $successTitle): bool
    {
        try {
            $move();
        } catch (AuthorizationException) {
            self::refuse(__('You are not allowed to do that.'));

            return false;
        } catch (InvalidTransition $exception) {
            self::refuse($exception->getMessage());

            return false;
        }

        Notification::make()->success()->title($successTitle)->send();

        return true;
    }

    /** @param  string  $message  already translated */
    public static function refuse(string $message): void
    {
        Notification::make()->danger()->title(__('Nothing was changed'))->body($message)->send();
    }
}
