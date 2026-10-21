<?php

namespace App\Editorial;

use App\Enums\TranslationStatus;
use DomainException;

/** Its message is shown to staff (WorkflowOutcome::refuse), so it is translated. */
class InvalidTransition extends DomainException
{
    public static function notAllowedFrom(Transition $transition, TranslationStatus $current): self
    {
        // English has always named the stored values ("in_review"); other languages name the labels staff see.
        $labels = app()->getLocale() !== 'en';

        return new self(__('Cannot :transition a translation that is :status.', [
            'transition' => $labels ? $transition->label() : $transition->value,
            'status' => $labels ? $current->label() : $current->value,
        ]));
    }

    public static function noteRequired(): self
    {
        return new self(__('A note explaining the requested changes is required.'));
    }

    public static function scheduledTooFar(): self
    {
        return new self(__('A story can be scheduled at most one year ahead.'));
    }

    public static function incomplete(string $field): self
    {
        return new self(match ($field) {
            'title' => __('The translation cannot be submitted: title is empty.'),
            'body' => __('The translation cannot be submitted: body is empty.'),
            default => __('The translation cannot be submitted: :field is empty.', ['field' => $field]),
        });
    }
}
