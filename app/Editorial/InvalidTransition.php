<?php

namespace App\Editorial;

use App\Enums\TranslationStatus;
use DomainException;

class InvalidTransition extends DomainException
{
    public static function notAllowedFrom(Transition $transition, TranslationStatus $current): self
    {
        return new self("Cannot {$transition->value} a translation that is {$current->value}.");
    }

    public static function noteRequired(): self
    {
        return new self('A note explaining the requested changes is required.');
    }

    public static function scheduledTooFar(): self
    {
        return new self('A story can be scheduled at most one year ahead.');
    }

    public static function incomplete(string $field): self
    {
        return new self("The translation cannot be submitted: {$field} is empty.");
    }
}
