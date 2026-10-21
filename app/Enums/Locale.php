<?php

namespace App\Enums;

enum Locale: string
{
    case Ar = 'ar';
    case En = 'en';

    public static function default(): self
    {
        return self::Ar;
    }

    /** The language the current request is shown in (public page or staff panel). */
    public static function current(): self
    {
        return self::tryFrom(app()->getLocale()) ?? self::default();
    }

    public static function values(): array
    {
        return array_map(fn (self $l) => $l->value, self::cases());
    }

    public static function tryFromRequestLanguage(?string $acceptLanguage): self
    {
        if ($acceptLanguage && preg_match('/^\s*en\b/i', $acceptLanguage)) {
            return self::En;
        }

        return self::default();
    }

    public function dir(): string
    {
        return $this === self::Ar ? 'rtl' : 'ltr';
    }

    public function label(): string
    {
        return $this === self::Ar ? 'العربية' : 'English';
    }

    public function other(): self
    {
        return $this === self::Ar ? self::En : self::Ar;
    }
}
