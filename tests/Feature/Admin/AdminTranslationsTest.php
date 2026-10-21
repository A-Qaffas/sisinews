<?php

namespace Tests\Feature\Admin;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

/**
 * Every English string the staff panel translates (__('...') with the English text as the key) has an Arabic entry
 * in lang/ar.json, with the same :placeholders. Without it the journalists, who do not read English, would meet an
 * English button in an Arabic panel. Group keys ("layout.masthead.editor") and package keys ("filament::...") live
 * in their own files and are skipped here.
 */
class AdminTranslationsTest extends TestCase
{
    private const SOURCES = [
        'app/Filament',
        'app/Enums',
        'app/Editorial',
        'app/Policies',
        'app/Models',
        'app/Providers/Filament',
        'app/Http/Controllers/Admin',
        'resources/views/filament',
    ];

    /** @return array<string, string> English key => Arabic */
    private function arabic(): array
    {
        $json = json_decode((string) file_get_contents(base_path('lang/ar.json')), true, flags: JSON_THROW_ON_ERROR);
        $this->assertIsArray($json);

        return $json;
    }

    /** @return array<string, string> key => first file it appears in */
    private function keysInTheCode(): array
    {
        $keys = [];

        foreach (self::SOURCES as $dir) {
            $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path($dir), RecursiveDirectoryIterator::SKIP_DOTS));

            foreach ($files as $file) {
                if (! str_ends_with($file->getFilename(), '.php')) {
                    continue;
                }

                $source = (string) file_get_contents($file->getPathname());
                // __('...') / __("...") / trans_choice('...'): single- or double-quoted literal first argument.
                preg_match_all('/(?:\b__|\btrans_choice)\(\s*(\'(?:[^\'\\\\]|\\\\.)*\'|"(?:[^"\\\\]|\\\\.)*")/s', $source, $matches);

                foreach ($matches[1] as $literal) {
                    $key = stripcslashes(substr($literal, 1, -1));

                    if (str_contains($key, '::') || preg_match('/^[a-z0-9_-]+(\.[a-z0-9_-]+)+$/', $key) === 1) {
                        continue;
                    }

                    $keys[$key] ??= str_replace(base_path().DIRECTORY_SEPARATOR, '', $file->getPathname());
                }
            }
        }

        return $keys;
    }

    public function test_the_panel_code_uses_translated_strings_at_all(): void
    {
        $this->assertGreaterThan(200, count($this->keysInTheCode()), 'the scan found too few keys: the pattern is broken');
    }

    public function test_every_string_the_panel_translates_has_an_arabic_entry(): void
    {
        $missing = array_diff_key($this->keysInTheCode(), $this->arabic());

        $this->assertSame([], $missing, 'English keys with no Arabic in lang/ar.json (key => file)');
    }

    /**
     * __('Article') with no JSON line returns the whole lang/xx/article.php array, not a string (and the file match
     * ignores case on Windows). English has no JSON file, so such a key would break every English page.
     */
    public function test_no_key_is_the_name_of_a_language_file(): void
    {
        $groups = [];
        foreach (glob(base_path('lang/*/*.php')) as $file) {
            $groups[strtolower(basename($file, '.php'))] = true;
        }

        $english = json_decode((string) file_get_contents(base_path('lang/en.json')), true, flags: JSON_THROW_ON_ERROR);
        $arabic = $this->arabic();

        $clashes = array_filter(
            array_keys($this->keysInTheCode()),
            fn (string $key): bool => isset($groups[strtolower($key)]) && ! (isset($english[$key]) && isset($arabic[$key])),
        );

        $this->assertSame([], array_values($clashes), 'rename these keys (e.g. "News story") or give them a line in lang/en.json and lang/ar.json');
    }

    public function test_the_arabic_keeps_the_same_placeholders_and_is_arabic(): void
    {
        foreach ($this->arabic() as $english => $arabic) {
            preg_match_all('/:[a-zA-Z_]+/', $english, $from);
            preg_match_all('/:[a-zA-Z_]+/', $arabic, $to);
            sort($from[0]);
            sort($to[0]);

            $this->assertSame($from[0], $to[0], "placeholders differ for \"{$english}\"");
            $this->assertMatchesRegularExpression('/\p{Arabic}/u', $arabic, "\"{$english}\" has no Arabic translation");
        }
    }
}
