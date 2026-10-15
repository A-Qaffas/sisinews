<?php

namespace App\Services;

use App\Models\ArticleTranslation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Adds ONE view to today's article_daily_views row for a translation, at most once
 * per session per translation, skipping bots and non-visible translations. The
 * increment is a single atomic upsert (no read-modify-write) and a failure is
 * reported, never thrown into the page render.
 */
final class ViewCounter
{
    /** Obvious crawlers and link-preview fetchers (matched case-insensitively in the user agent). */
    private const BOT_MARKERS = ['bot', 'crawl', 'spider', 'slurp', 'preview'];

    private const SESSION_KEY = 'viewed_translations';

    /** Bounds the session payload for a reader who opens hundreds of stories. */
    private const REMEMBERED = 300;

    public function record(ArticleTranslation $t, Request $request): void
    {
        // HEAD requests are routed to the GET handler, and a request without a session
        // cannot be de-duplicated, so neither may inflate the counter.
        if (! $request->isMethod('GET') || ! $request->hasSession() || ! $t->isVisible() || $this->isBot($request)) {
            return;
        }

        $session = $request->session();
        $seen = (array) $session->get(self::SESSION_KEY, []);
        $id = (int) $t->getKey();

        if (in_array($id, $seen, true)) {
            return;
        }

        try {
            DB::table('article_daily_views')->upsert(
                [['article_translation_id' => $id, 'day' => now()->toDateString(), 'views' => 1]],
                ['article_translation_id', 'day'],
                ['views' => DB::raw('views + 1')],
            );
        } catch (Throwable $e) {
            report($e);

            return;
        }

        $seen[] = $id;
        $session->put(self::SESSION_KEY, array_slice($seen, -self::REMEMBERED));
    }

    private function isBot(Request $request): bool
    {
        $agent = strtolower((string) $request->userAgent());

        foreach (self::BOT_MARKERS as $marker) {
            if (str_contains($agent, $marker)) {
                return true;
            }
        }

        return false;
    }
}
