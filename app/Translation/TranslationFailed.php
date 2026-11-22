<?php

namespace App\Translation;

use RuntimeException;

/** The English edition could not be produced this time; the Arabic story is unaffected and a later run tries again. */
class TranslationFailed extends RuntimeException
{
    public function __construct(string $message, public readonly bool $rateLimited = false)
    {
        parent::__construct($message);
    }
}
