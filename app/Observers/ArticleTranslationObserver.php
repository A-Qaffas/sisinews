<?php

namespace App\Observers;

use App\Enums\Locale;
use App\Enums\TranslationStatus;
use App\Jobs\TranslateStoryToEnglish;
use App\Models\ArticleTranslation;
use App\Translation\GeminiTranslator;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;

/**
 * When an Arabic version is published, unpublished, or its text changes while published, the English edition is
 * brought in line after the response (App\Translation\EnglishEdition decides what, if anything, to do).
 * Runs after the database commit, so the workflow's row lock is not held while the translation service answers.
 */
class ArticleTranslationObserver implements ShouldHandleEventsAfterCommit
{
    public function saved(ArticleTranslation $translation): void
    {
        if ($translation->locale !== Locale::Ar || ! GeminiTranslator::enabled()) {
            return;
        }
        if (! in_array($translation->status, [TranslationStatus::Published, TranslationStatus::Archived], true)) {
            return;
        }
        if (! $translation->wasChanged('status') && ! $translation->wasChanged(GeminiTranslator::FIELDS) && ! $translation->wasRecentlyCreated) {
            return;
        }

        TranslateStoryToEnglish::dispatchAfterResponse($translation->getKey());
    }
}
