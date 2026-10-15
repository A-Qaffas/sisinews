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
     * @return bool whether the move happened
     */
    public static function run(Closure $move, string $successTitle): bool
    {
        try {
            $move();
        } catch (AuthorizationException) {
            self::refuse('You are not allowed to do that.');

            return false;
        } catch (InvalidTransition $exception) {
            self::refuse($exception->getMessage());

            return false;
        }

        Notification::make()->success()->title($successTitle)->send();

        return true;
    }

    public static function refuse(string $message): void
    {
        Notification::make()->danger()->title('Nothing was changed')->body($message)->send();
    }
}
