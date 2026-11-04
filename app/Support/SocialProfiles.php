<?php

namespace App\Support;

use Illuminate\Support\Str;

/** The site's social accounts from config/site.php: the footer links and the publisher's sameAs share one rule. */
final class SocialProfiles
{
    private const LABELS = ['facebook' => 'Facebook', 'x' => 'X', 'instagram' => 'Instagram', 'youtube' => 'YouTube'];

    /** @return array<string, string> label => url, blank or non-http(s) entries dropped */
    public static function links(): array
    {
        $links = [];

        foreach (self::LABELS as $key => $label) {
            $url = config("site.social.{$key}");

            if (is_string($url) && Str::startsWith($url, ['https://', 'http://'])) {
                $links[$label] = $url;
            }
        }

        return $links;
    }
}
