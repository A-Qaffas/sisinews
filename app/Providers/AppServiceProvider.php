<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Symfony\Component\HttpFoundation\Exception\SuspiciousOperationException;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        RateLimiter::for('newsletter', fn (Request $request) => Limit::perMinute(5)->by($request->ip()));

        // A switch, not "production implies https": the host serves plain http until its certificate exists.
        if (config('app.force_https')) {
            URL::forceScheme('https');
        }

        // Console runs (seeders, artisan) have no visitor, so they keep linking pictures from APP_URL.
        if (! $this->app->runningInConsole()) {
            $this->publicDiskFollowsRequest($this->app->make('request'));
        }
    }

    /**
     * Uploaded and launch pictures live on the public disk, whose URL is built from APP_URL when the
     * config loads. With APP_URL saying https while the site is served over http (or reached on a
     * second hostname) every picture would point at an address the visitor never used, and break.
     * In a web request the disk therefore links from the scheme and host of that very request,
     * exactly like every other URL the app generates (url(), route(), asset()).
     *
     * Public only so the tests can drive it; boot() is the one caller.
     */
    public function publicDiskFollowsRequest(Request $request): void
    {
        try {
            $host = $request->getHttpHost();
        } catch (SuspiciousOperationException) {
            // A malformed Host header: keep the APP_URL default rather than failing a page (or the /up check) over it.
            return;
        }

        $scheme = config('app.force_https') ? 'https' : $request->getScheme();

        config(['filesystems.disks.public.url' => $scheme.'://'.$host.$request->getBasePath().'/storage']);

        // A disk resolved before this point would keep the old URL.
        Storage::forgetDisk('public');
    }
}
