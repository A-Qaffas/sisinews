<?php

namespace Tests\Feature;

use Tests\TestCase;

class RootRedirectTest extends TestCase
{
    public function test_root_redirects_to_arabic_for_an_arabic_browser(): void
    {
        $this->get('/', ['Accept-Language' => 'ar-EG,ar;q=0.9,en;q=0.8'])->assertRedirect('/ar');
    }

    public function test_root_redirects_to_arabic_when_the_browser_sends_no_language(): void
    {
        // Symfony's test request injects "en-us" by default, so blank it explicitly.
        $this->get('/', ['Accept-Language' => ''])->assertRedirect('/ar');
    }

    public function test_root_opens_arabic_even_when_the_browser_prefers_english(): void
    {
        // The owner's choice: sisi.news opens in Arabic for everyone; English is one click away in the top bar.
        $this->get('/', ['Accept-Language' => 'en-US,en;q=0.9,ar;q=0.8'])->assertStatus(302)->assertRedirect('/ar');
    }

    public function test_unknown_locale_segment_is_a_404_not_a_crash(): void
    {
        $this->get('/fr')->assertNotFound();
    }
}
