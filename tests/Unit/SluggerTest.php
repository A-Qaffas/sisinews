<?php

namespace Tests\Unit;

use App\Enums\Locale;
use App\Support\Slugger;
use PHPUnit\Framework\TestCase;

class SluggerTest extends TestCase
{
    public function test_keeps_arabic_letters_and_joins_words_with_dashes(): void
    {
        $this->assertSame('مصر-تستضيف-القمة', Slugger::make('مصر تستضيف القمة'));
    }

    public function test_strips_arabic_diacritics_tatweel_and_punctuation(): void
    {
        $this->assertSame('مرحبا-بالعالم', Slugger::make('مَرْحَباً — بـالعالم!؟'));
    }

    public function test_latin_is_lowercased_and_collapsed(): void
    {
        $this->assertSame('hello-world-2026', Slugger::make('  Hello,   World -- 2026!  '));
    }

    public function test_is_truncated_without_a_trailing_dash(): void
    {
        $slug = Slugger::make(str_repeat('كلمة ', 40), 20);
        $this->assertLessThanOrEqual(20, mb_strlen($slug));
        $this->assertStringEndsNotWith('-', $slug);
    }

    public function test_empty_or_symbol_only_input_yields_an_empty_slug(): void
    {
        $this->assertSame('', Slugger::make('!!! ??? ---'));
    }

    public function test_locale_is_arabic_unless_the_browser_prefers_english(): void
    {
        $this->assertSame(Locale::Ar, Locale::tryFromRequestLanguage(null));
        $this->assertSame(Locale::Ar, Locale::tryFromRequestLanguage('ar-EG,ar;q=0.9,en;q=0.8'));
        $this->assertSame(Locale::En, Locale::tryFromRequestLanguage('en-US,en;q=0.9,ar;q=0.8'));
        $this->assertSame(Locale::Ar, Locale::tryFromRequestLanguage('fr-FR'));
    }
}
