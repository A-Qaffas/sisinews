<?php

namespace App\Console\Commands;

use App\Enums\Locale;
use App\Enums\TranslationStatus;
use App\Models\ArticleTranslation;
use App\Translation\EnglishEdition;
use App\Translation\GeminiTranslator;
use App\Translation\TranslationFailed;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Catches up the English edition: published Arabic stories without an English version (the back catalogue, or a
 * translation that failed at publication) and machine-made English versions whose Arabic changed since. Newest first,
 * so today's news always comes before the archive. Meant for cron every few minutes with a small --limit; stops at the
 * first "too many requests" so the shared free quota is not hammered.
 */
#[Signature('sisi:translate-english
    {--limit=5 : Stories to translate in this run at most}
    {--pause=5 : Seconds to wait between two translations}
    {--dry-run : List what would be translated, change nothing}')]
#[Description('Translate published Arabic stories that have no up-to-date English version yet, newest first')]
class TranslateEnglishCommand extends Command
{
    public function handle(EnglishEdition $edition): int
    {
        if (! GeminiTranslator::enabled() && ! $this->option('dry-run')) {
            $this->error('No translation key is configured (GEMINI_API_KEY in .env).');

            return self::FAILURE;
        }

        $limit = max(1, (int) $this->option('limit'));
        $pause = max(0, (int) $this->option('pause'));
        $done = 0;
        $failed = 0;

        $pending = $this->pending();
        if ($pending === []) {
            $this->info('Every published Arabic story has an up-to-date English version.');

            return self::SUCCESS;
        }
        $this->line(count($pending).' stories need an English version.');

        foreach (array_slice($pending, 0, $limit) as $i => $arabic) {
            if ($this->option('dry-run')) {
                $this->line("  story {$arabic->article_id}: {$arabic->title}");

                continue;
            }
            if ($i > 0 && $pause > 0) {
                sleep($pause);
            }

            try {
                $this->line("story {$arabic->article_id}: ".$edition->sync($arabic));
                $done++;
            } catch (TranslationFailed $e) {
                $failed++;
                $this->warn("story {$arabic->article_id}: {$e->getMessage()}");
                if ($e->rateLimited) {
                    $this->warn('The translation service asked to slow down; the rest waits for the next run.');

                    break;
                }
            }
        }

        $this->info("Done: {$done} translated, {$failed} failed.");

        return $failed > 0 && $done === 0 ? self::FAILURE : self::SUCCESS;
    }

    /** @return list<ArticleTranslation> Arabic versions whose English is missing or stale, newest first. */
    private function pending(): array
    {
        $english = ArticleTranslation::query()
            ->where('locale', Locale::En->value)
            ->get()
            ->keyBy('article_id');

        return ArticleTranslation::query()
            ->where('locale', Locale::Ar->value)
            ->where('status', TranslationStatus::Published->value)
            ->whereHas('article')
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->get()
            ->filter(function (ArticleTranslation $arabic) use ($english): bool {
                $en = $english->get($arabic->article_id);
                if ($en === null) {
                    return true;
                }
                if (! EnglishEdition::isMachineMade($en) || $en->status === TranslationStatus::Archived) {
                    return false;
                }

                return $en->status !== TranslationStatus::Published || $en->auto_source_hash !== EnglishEdition::hash($arabic);
            })
            ->values()
            ->all();
    }
}
