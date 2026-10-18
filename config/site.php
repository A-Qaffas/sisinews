<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Brand
    |--------------------------------------------------------------------------
    | The wordmark is Latin ("sisi.news") in both languages, so the name is the
    | same until the owner decides on a separate Arabic masthead.
    */
    'name' => [
        'ar' => 'sisi.news',
        'en' => 'sisi.news',
    ],

    'tagline' => [
        'ar' => 'مصر في بؤرة الضوء',
        'en' => 'Egypt in Focus',
    ],

    'theme_color' => '#162938',

    /** Default share image (the current logo on the cream field) for pages without a hero image. */
    'og_image' => 'brand/og-default.png',

    /** Reader-facing dates are shown in Cairo time; the database stays in UTC. */
    'timezone' => 'Africa/Cairo',

    /*
    |--------------------------------------------------------------------------
    | Social profiles and contact
    |--------------------------------------------------------------------------
    | Blank entries are hidden from the footer. Set them in .env when the
    | accounts exist (SITE_SOCIAL_FACEBOOK=https://facebook.com/...).
    */
    'social' => [
        'facebook' => env('SITE_SOCIAL_FACEBOOK'),
        'x' => env('SITE_SOCIAL_X'),
        'instagram' => env('SITE_SOCIAL_INSTAGRAM'),
        'youtube' => env('SITE_SOCIAL_YOUTUBE'),
    ],

    'contact' => [
        'email' => env('SITE_CONTACT_EMAIL'),
        'phone' => env('SITE_CONTACT_PHONE'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Public cache lifetimes (seconds)
    |--------------------------------------------------------------------------
    | Short on purpose: readers see a new story within a minute, while the
    | shared host runs each expensive query at most once per window. 0 disables.
    */
    'cache' => [
        'home' => 60,
        'ticker' => 60,
        'navigation' => 60,
        'most_read' => 300,
        // Live-now strip and the nav dot: short so a broadcast that ended stops showing as live within a minute.
        'live' => 30,
        // Site settings (masthead names): dropped on every save, so this long lifetime is only a safety net.
        'settings' => 86400,
    ],

];
