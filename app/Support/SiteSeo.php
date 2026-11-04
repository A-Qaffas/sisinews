<?php

namespace App\Support;

use App\Enums\Locale;

/** Site-wide structured data: who publishes sisi.news, for the home page. Stories carry their own (ArticleSeo). */
final class SiteSeo
{
    /** @return array<string, mixed> */
    public static function homeJsonLd(Locale $locale): array
    {
        $root = url('/');
        $name = (string) config('site.name.'.$locale->value, config('app.name'));

        $organization = [
            '@type' => 'NewsMediaOrganization',
            '@id' => $root.'#organization',
            'name' => $name,
            'url' => $root,
            'logo' => ['@type' => 'ImageObject', 'url' => ArticleSeo::logoUrl()],
        ];
        // The same accounts the footer links to: set in .env, blank or non-http(s) entries dropped.
        $profiles = array_values(SocialProfiles::links());
        if ($profiles !== []) {
            $organization['sameAs'] = $profiles;
        }

        return [
            '@context' => 'https://schema.org',
            '@graph' => [
                $organization,
                [
                    '@type' => 'WebSite',
                    '@id' => $root.'#website',
                    'name' => $name,
                    'url' => route('home', ['locale' => $locale->value]),
                    'inLanguage' => $locale->value,
                    'publisher' => ['@id' => $root.'#organization'],
                ],
            ],
        ];
    }
}
