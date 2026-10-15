<?php

namespace Tests\Unit;

use App\Enums\LiveProvider;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Whatever an editor pastes, only a YouTube or Vimeo video id can come out of LiveProvider::fromInput(); the public
 * page then builds the player address from a fixed template, so a hostile link can never reach a reader.
 */
class LiveProviderTest extends TestCase
{
    private const YT = 'dQw4w9WgXcQ';

    private const YT_DASHES = 'a-B_c-D_e-F';

    private const VIMEO = '123456789';

    /** @return array<string, array{0: string, 1: LiveProvider, 2: string}> */
    public static function acceptedInputs(): array
    {
        $yt = self::YT;
        $vm = self::VIMEO;

        return [
            'watch link' => ["https://www.youtube.com/watch?v={$yt}", LiveProvider::YouTube, $yt],
            'watch link without www' => ["https://youtube.com/watch?v={$yt}", LiveProvider::YouTube, $yt],
            'mobile watch link' => ["https://m.youtube.com/watch?v={$yt}", LiveProvider::YouTube, $yt],
            'watch link with query noise before' => ["https://www.youtube.com/watch?feature=share&v={$yt}", LiveProvider::YouTube, $yt],
            'watch link with query noise after' => ["https://www.youtube.com/watch?v={$yt}&t=42s&list=PL123&si=abc", LiveProvider::YouTube, $yt],
            'watch link with a fragment' => ["https://www.youtube.com/watch?v={$yt}#t=10", LiveProvider::YouTube, $yt],
            'short link' => ["https://youtu.be/{$yt}", LiveProvider::YouTube, $yt],
            'short link with a trailing slash' => ["https://youtu.be/{$yt}/", LiveProvider::YouTube, $yt],
            'short link with share noise' => ["https://youtu.be/{$yt}?si=AbCdEf&t=5", LiveProvider::YouTube, $yt],
            'live link' => ["https://www.youtube.com/live/{$yt}", LiveProvider::YouTube, $yt],
            'live link with a trailing slash' => ["https://www.youtube.com/live/{$yt}/", LiveProvider::YouTube, $yt],
            'live link with share noise' => ["https://www.youtube.com/live/{$yt}?si=xyz", LiveProvider::YouTube, $yt],
            'embed link' => ["https://www.youtube.com/embed/{$yt}", LiveProvider::YouTube, $yt],
            'no-cookie embed link' => ["https://www.youtube-nocookie.com/embed/{$yt}", LiveProvider::YouTube, $yt],
            'uppercase host' => ["https://WWW.YOUTUBE.COM/watch?v={$yt}", LiveProvider::YouTube, $yt],
            'mixed case host on the short domain' => ["https://YouTu.Be/{$yt}", LiveProvider::YouTube, $yt],
            'ids with dashes and underscores' => ['https://youtu.be/'.self::YT_DASHES, LiveProvider::YouTube, self::YT_DASHES],
            'surrounding whitespace' => ["  https://youtu.be/{$yt}  \n", LiveProvider::YouTube, $yt],
            'bare YouTube id' => [$yt, LiveProvider::YouTube, $yt],
            'bare YouTube id with dashes' => [self::YT_DASHES, LiveProvider::YouTube, self::YT_DASHES],
            'bare id with whitespace around it' => ["\t{$yt} ", LiveProvider::YouTube, $yt],
            'vimeo page' => ["https://vimeo.com/{$vm}", LiveProvider::Vimeo, $vm],
            'vimeo page with www' => ["https://www.vimeo.com/{$vm}", LiveProvider::Vimeo, $vm],
            'vimeo page with a trailing slash' => ["https://vimeo.com/{$vm}/", LiveProvider::Vimeo, $vm],
            'vimeo page with query noise' => ["https://vimeo.com/{$vm}?share=copy", LiveProvider::Vimeo, $vm],
            'vimeo player link' => ["https://player.vimeo.com/video/{$vm}", LiveProvider::Vimeo, $vm],
            'vimeo player link with options' => ["https://player.vimeo.com/video/{$vm}?h=abcdef1234&dnt=1", LiveProvider::Vimeo, $vm],
            'bare vimeo id' => [$vm, LiveProvider::Vimeo, $vm],
            'short bare vimeo id' => ['123456', LiveProvider::Vimeo, '123456'],
            'long bare vimeo id' => ['123456789012', LiveProvider::Vimeo, '123456789012'],
        ];
    }

    #[DataProvider('acceptedInputs')]
    public function test_a_recognised_link_or_id_gives_the_provider_and_the_bare_id(string $input, LiveProvider $provider, string $id): void
    {
        $this->assertSame([$provider, $id], LiveProvider::fromInput($input));
    }

    /** @return array<string, array{0: string}> */
    public static function rejectedInputs(): array
    {
        $yt = self::YT;
        $vm = self::VIMEO;

        return [
            'empty' => [''],
            'blank' => ["   \n\t"],
            'plain text' => ['watch the live stream'],
            'shorts are not live broadcasts' => ["https://www.youtube.com/shorts/{$yt}"],
            'a channel page' => ['https://www.youtube.com/@aljazeera'],
            'a channel live page' => ['https://www.youtube.com/@aljazeera/live'],
            'a playlist' => ['https://www.youtube.com/playlist?list=PL1234567890A'],
            'a watch page without a video' => ['https://www.youtube.com/watch'],
            'a watch page with a short id' => ['https://www.youtube.com/watch?v=short'],
            'a watch page with a too long id' => ['https://www.youtube.com/watch?v=dQw4w9WgXcQextra'],
            'a watch page whose id is an array' => ['https://www.youtube.com/watch?v[]=dQw4w9WgXcQ'],
            'a watch path that is not /watch' => ["https://www.youtube.com/watch/extra?v={$yt}"],
            'the YouTube home page' => ['https://www.youtube.com/'],
            'a Vimeo channel page' => ['https://vimeo.com/channels/staffpicks'],
            'a Vimeo page with a private hash' => ["https://vimeo.com/{$vm}/abcdef1234"],
            'a Vimeo player link without /video/' => ["https://player.vimeo.com/{$vm}"],
            'a too short Vimeo id' => ['https://vimeo.com/12345'],
            'a too long Vimeo id' => ['https://vimeo.com/1234567890123'],
            'plain http YouTube' => ["http://www.youtube.com/watch?v={$yt}"],
            'plain http short link' => ["http://youtu.be/{$yt}"],
            'plain http Vimeo' => ["http://vimeo.com/{$vm}"],
            'a scheme-less link' => ["www.youtube.com/watch?v={$yt}"],
            'a protocol-relative link' => ["//www.youtube.com/watch?v={$yt}"],
            'a host that only starts like YouTube' => ["https://youtube.com.evil.com/watch?v={$yt}"],
            'a host that only ends like YouTube' => ["https://evilyoutube.com/watch?v={$yt}"],
            'a look-alike subdomain of the attacker' => ["https://www.youtube.com.attacker.example/watch?v={$yt}"],
            'a YouTube address in the path of another host' => ["https://evil.com/youtu.be/{$yt}"],
            'a YouTube address in the query of another host' => ["https://evil.com/watch?v={$yt}&u=https://www.youtube.com/watch?v={$yt}"],
            'another host with the right path' => ["https://evil.com/watch?v={$yt}"],
            'a Vimeo address on another host' => ["https://vimeo.com.evil.com/{$vm}"],
            'a subdomain other than www, m or player' => ["https://studio.youtube.com/video/{$yt}/edit"],
            'a different video site' => ['https://www.dailymotion.com/video/x8abcde'],
            'ftp' => ["ftp://youtu.be/{$yt}"],
            'javascript' => ['javascript:alert(1)'],
            'javascript wrapped around a YouTube address' => ["javascript:alert('https://youtu.be/{$yt}')"],
            'uppercase javascript' => ['JAVASCRIPT:alert(document.cookie)'],
            'javascript with a comment and a YouTube address' => ["javascript://youtu.be/{$yt}%0Aalert(1)"],
            'data url' => ['data:text/html,<script>alert(1)</script>'],
            'data url naming YouTube' => ["data:text/html;base64,PHNjcmlwdD5hbGVydCgxKTwvc2NyaXB0Pg==,https://youtu.be/{$yt}"],
            'a view-source url' => ["view-source:https://youtu.be/{$yt}"],
            'a file url' => ['file:///etc/passwd'],
            'markup instead of a link' => ['<iframe src="https://evil.example"></iframe>'],
            'a script tag' => ['<script>alert(1)</script>'],
            'an iframe around a real id' => ["<iframe src=\"https://www.youtube.com/embed/{$yt}\"></iframe>"],
            'an id followed by markup' => [self::YT.'"><script>'],
            'a link followed by a second link' => ["https://youtu.be/{$yt} https://evil.com"],
            'a link with a line break and more text' => ["https://youtu.be/{$yt}\nhttps://evil.com"],
            'an encoded id' => ['https://youtu.be/dQw4w9WgXc%51'],
            'a link with a null byte' => ["https://youtu.be/{$yt}\0evil"],
            'userinfo that hides another host' => ["https://youtu.be@evil.com/{$yt}"],
            'ten characters is not an id' => ['dQw4w9WgXc'],
            'twelve letters is not an id' => ['dQw4w9WgXcQa'],
            'an id with a dot' => ['dQw4w9WgXc.'],
            'an id with a space inside' => ['dQw4w9 WgXc'],
            'a five digit number' => ['12345'],
            'a thirteen digit number' => ['1234567890123'],
            'a negative number' => ['-1234567'],
            'a decimal number' => ['1234567.5'],
            'a very long string' => [str_repeat('a', 5000)],
            'a very long path' => ['https://youtu.be/'.str_repeat('a', 5000)],
            'a very long digit string' => [str_repeat('1', 5000)],
        ];
    }

    #[DataProvider('rejectedInputs')]
    public function test_anything_else_is_refused(string $input): void
    {
        $this->assertNull(LiveProvider::fromInput($input));
    }

    /** @return array<string, array{0: string}> */
    public static function lookalikeInputs(): array
    {
        $yt = self::YT;

        return [
            'a Cyrillic u in youtube' => ["https://www.yоutube.com/watch?v={$yt}"],
            'a Cyrillic a in the short host' => ["https://youtu.bе/{$yt}"],
            'a Greek omicron in vimeo' => ['https://vimeο.com/123456789'],
            'full-width letters in the host' => ["https://ｙｏｕｔｕ.be/{$yt}"],
            'an Arabic-Indic digit id for Vimeo' => ['١٢٣٤٥٦٧٨٩'],
            'an Arabic-Indic digit id in a Vimeo link' => ['https://vimeo.com/١٢٣٤٥٦٧٨٩'],
            'full-width digits' => ['１２３４５６７８９'],
            'a Cyrillic letter inside an eleven character id' => ['dQw4w9WgXcО'],
            'an accented letter in the id' => ['dQw4w9WgXcé'],
            'an Arabic letter in the id' => ['dQw4w9WgXcب'],
            'a zero-width space inside the id' => ["dQw4w9\u{200B}WgXcQ"],
            'a right-to-left mark before the link' => ["\u{200F}https://youtu.be/{$yt}"],
            'a no-break space before the link' => ["\u{00A0}https://youtu.be/{$yt}"],
            'a Unicode dash instead of a hyphen in the id' => ["a\u{2010}B_c-D_e-F"],
            'a dotless i in the scheme' => ["httpѕ://youtu.be/{$yt}"],
        ];
    }

    #[DataProvider('lookalikeInputs')]
    public function test_unicode_lookalikes_are_refused(string $input): void
    {
        $this->assertNull(LiveProvider::fromInput($input));
    }

    public function test_everything_that_is_accepted_yields_only_a_plain_id(): void
    {
        $ids = [];
        foreach (array_merge(self::acceptedInputs(), self::rejectedInputs(), self::lookalikeInputs()) as [$input]) {
            $result = LiveProvider::fromInput($input);
            if ($result === null) {
                continue;
            }

            [$provider, $id] = $result;
            $ids[] = $id;
            $this->assertMatchesRegularExpression(
                $provider === LiveProvider::YouTube ? '/\A[A-Za-z0-9_-]{11}\z/' : '/\A\d{6,12}\z/',
                $id,
            );
        }

        $this->assertNotEmpty($ids);
    }

    public function test_a_normal_tracking_query_does_not_change_what_is_read(): void
    {
        $link = 'https://www.youtube.com/watch?si=abc123&v='.self::YT.'&t=42&feature=share&junk='.str_repeat('a', 200);

        $this->assertSame([LiveProvider::YouTube, self::YT], LiveProvider::fromInput($link));
    }

    public function test_an_overlong_link_is_refused_before_it_is_parsed(): void
    {
        $this->assertNull(LiveProvider::fromInput('https://www.youtube.com/watch?v='.self::YT.'&junk='.str_repeat('a', 5000)));
    }

    public function test_thousands_of_query_parameters_cannot_cause_an_error(): void
    {
        // 1,000+ parameters made parse_str() warn (max_input_vars), and Laravel turns warnings into a 500.
        $link = 'https://www.youtube.com/watch?'.str_repeat('a=1&', 500).'v='.self::YT;

        $this->assertSame([LiveProvider::YouTube, self::YT], LiveProvider::fromInput($link));
    }

    public function test_userinfo_port_and_backslash_tricks_are_refused(): void
    {
        foreach ([
            'https://evil.com\\@youtu.be/'.self::YT,
            'https://youtu.be@evil.com/'.self::YT,
            'https://user:pass@youtu.be/'.self::YT,
            'https://youtu.be:8443/'.self::YT,
            "https://youtu.be/\t".self::YT,
        ] as $input) {
            $this->assertNull(LiveProvider::fromInput($input), $input);
        }
    }

    public function test_an_eleven_digit_number_is_read_as_a_youtube_id(): void
    {
        // A YouTube id may be all digits, and that wins over a Vimeo id of 11 digits: the form shows what it
        // recognised, and a full Vimeo link is always unambiguous.
        $this->assertSame([LiveProvider::YouTube, '12345678901'], LiveProvider::fromInput('12345678901'));
        $this->assertSame([LiveProvider::Vimeo, '12345678901'], LiveProvider::fromInput('https://vimeo.com/12345678901'));
    }

    public function test_the_player_address_comes_from_a_fixed_template_on_a_known_host(): void
    {
        $youtube = LiveProvider::YouTube->embedUrl(self::YT);
        $vimeo = LiveProvider::Vimeo->embedUrl(self::VIMEO);

        $this->assertSame('https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ?autoplay=1&rel=0&playsinline=1', $youtube);
        $this->assertSame('https://player.vimeo.com/video/123456789?autoplay=1&dnt=1', $vimeo);
        $this->assertContains('https://'.parse_url($youtube, PHP_URL_HOST), LiveProvider::frameHosts());
        $this->assertContains('https://'.parse_url($vimeo, PHP_URL_HOST), LiveProvider::frameHosts());
    }

    public function test_an_id_is_escaped_in_the_player_address_even_if_one_slipped_through(): void
    {
        $url = LiveProvider::YouTube->embedUrl('x/../"><script>');

        $this->assertStringStartsWith('https://www.youtube-nocookie.com/embed/', $url);
        $this->assertStringNotContainsString('<', $url);
        $this->assertStringNotContainsString('"', $url);
        $this->assertSame('www.youtube-nocookie.com', parse_url($url, PHP_URL_HOST));
    }

    public function test_the_providers_have_readable_labels(): void
    {
        $this->assertSame('YouTube', LiveProvider::YouTube->label());
        $this->assertSame('Vimeo', LiveProvider::Vimeo->label());
    }
}
