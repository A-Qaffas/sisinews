<?php

namespace App\Jobs;

use App\Models\ArticleTranslation;
use App\Translation\EnglishEdition;
use App\Translation\TranslationFailed;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Log;

/**
 * Makes or refreshes the English edition of one Arabic version. Dispatched after the response (the editor does not
 * wait for the translation service); a failure is only logged, and `sisi:translate-english` (cron) picks it up later.
 */
class TranslateStoryToEnglish
{
    use Dispatchable;

    public function __construct(public readonly int $arabicTranslationId) {}

    public function handle(EnglishEdition $edition): void
    {
        $arabic = ArticleTranslation::query()->find($this->arabicTranslationId);
        if ($arabic === null) {
            return;
        }

        try {
            $outcome = $edition->sync($arabic);
            Log::info("English edition of story {$arabic->article_id}: {$outcome}");
        } catch (TranslationFailed $e) {
            Log::warning("English edition of story {$arabic->article_id} not made: {$e->getMessage()}");
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
