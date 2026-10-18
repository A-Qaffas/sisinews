<?php

namespace App\Support;

use App\Enums\Locale;
use App\Services\SiteSettings;
use Illuminate\Support\Facades\Lang;

/**
 * The masthead credits Egyptian news sites print beside the logo (رئيس مجلس الإدارة / رئيس التحرير), here also in the
 * footer. One name per role and language, set by an admin in Site > Site settings; an empty name hides that line.
 */
final class Masthead
{
    /** Role => translation key of its label, in the order the lines are printed. */
    public const ROLES = [
        'chairman' => 'layout.masthead.chairman',
        'editor' => 'layout.masthead.editor',
    ];

    public const MAX_LENGTH = 80;

    /** The site setting holding $role's name in $locale, for example "masthead_editor_ar". */
    public static function key(string $role, Locale $locale): string
    {
        return "masthead_{$role}_{$locale->value}";
    }

    /** @return list<string> */
    public static function keys(): array
    {
        $keys = [];
        foreach (array_keys(self::ROLES) as $role) {
            foreach (Locale::cases() as $locale) {
                $keys[] = self::key($role, $locale);
            }
        }

        return $keys;
    }

    /**
     * The lines for a page in $locale. A role whose name is empty in THAT language is left out: an English page never
     * falls back to the Arabic name, so no Arabic script appears on it.
     *
     * `fitted` is the label stretched to line up beside the logo (layout.masthead_fitted.*), when the language has one.
     *
     * @return list<array{key: string, role: string, fitted: ?string, name: string}>
     */
    public static function credits(Locale $locale): array
    {
        $settings = app(SiteSettings::class);
        $credits = [];

        foreach (self::ROLES as $role => $label) {
            $name = trim((string) $settings->get(self::key($role, $locale)));

            if ($name !== '') {
                $fitted = "layout.masthead_fitted.{$role}";
                $credits[] = [
                    'key' => $role,
                    'role' => __($label, [], $locale->value),
                    'fitted' => Lang::has($fitted, $locale->value, false) ? __($fitted, [], $locale->value) : null,
                    'name' => $name,
                ];
            }
        }

        return $credits;
    }
}
