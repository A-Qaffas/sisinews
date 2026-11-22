<?php

namespace App\Translation;

use App\Editorial\EditorialWorkflow;
use App\Enums\Locale;
use App\Enums\Permission;
use App\Enums\Role;
use App\Enums\TranslationStatus;
use App\Models\ArticleTranslation;
use App\Models\User;
use App\Support\Slugger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Keeps the English site carrying the same stories as the Arabic one: when an Arabic version is published, an English
 * version is machine-translated and published with it (same article, same publication time, so the English home page
 * shows the same line-up).
 *
 * Rules (decided with the owner on 2026-11-22):
 *  - English goes live at once. Status changes only through EditorialWorkflow, acted by the "Auto-translation" system
 *    account, so every move has its audit row like any other.
 *  - An English version a person wrote, or edited after the machine wrote it, is never touched again (auto_output_hash
 *    no longer matches its text). Otherwise a changed Arabic text is translated again.
 *  - An English version an editor unpublished stays unpublished. When the Arabic is unpublished, a machine-made English
 *    version is unpublished with it.
 */
class EnglishEdition
{
    /** The system account that acts in the audit trail. Hidden from the Team area and refused at the login (TeamGuard). */
    public const ACCOUNT_EMAIL = 'auto-translate@sisi.news';

    public function __construct(
        private readonly GeminiTranslator $translator,
        private readonly EditorialWorkflow $workflow,
    ) {}

    /**
     * Brings the English version of $arabic's story in line with it. Returns what happened, for logs and the command.
     *
     * @throws TranslationFailed when the translation service did not deliver; nothing was changed
     */
    public function sync(ArticleTranslation $arabic): string
    {
        $arabic = $arabic->fresh(['article']);
        if ($arabic === null || $arabic->locale !== Locale::Ar || $arabic->article === null) {
            return 'skipped';
        }

        $english = ArticleTranslation::query()
            ->where('article_id', $arabic->article_id)
            ->where('locale', Locale::En->value)
            ->first();

        if ($english !== null && ! self::isMachineMade($english)) {
            return 'written by a person';
        }

        if ($arabic->status === TranslationStatus::Archived) {
            if ($english?->status === TranslationStatus::Published) {
                $this->workflow->archive($english, self::account());

                return 'unpublished with the Arabic';
            }

            return 'skipped';
        }

        if ($arabic->status !== TranslationStatus::Published) {
            return 'Arabic not published';
        }
        if ($english?->status === TranslationStatus::Archived) {
            return 'unpublished by an editor';
        }

        $source = self::hash($arabic);
        if ($english?->auto_source_hash === $source && $english->status === TranslationStatus::Published) {
            return 'up to date';
        }

        $text = $english?->auto_source_hash === $source
            ? null // already translated, only the publication is missing
            : $this->translator->toEnglish($arabic->only(GeminiTranslator::FIELDS));

        $created = $english === null;

        DB::transaction(function () use (&$english, $arabic, $text, $source): void {
            $english ??= new ArticleTranslation(['article_id' => $arabic->article_id, 'locale' => Locale::En]);

            if ($text !== null) {
                $english->fill([
                    'title' => Str::limit($text['title'], 250, ''),
                    'slug' => Slugger::make($text['title']),
                    'excerpt' => $text['excerpt'],
                    'body' => $text['body'],
                    'hero_caption' => $text['hero_caption'] === null ? null : Str::limit($text['hero_caption'], 250, ''),
                    'meta_description' => $text['meta_description'] === null ? null : Str::limit($text['meta_description'], 300, ''),
                ]);
            }

            // A new English version carries the Arabic publication time, so both editions list the story in the same
            // place (EditorialWorkflow keeps a stamp already on the row). A future Arabic time is passed as a schedule.
            if (! $english->exists && $arabic->published_at !== null && ! $arabic->published_at->isFuture()) {
                $english->forceFill(['published_at' => $arabic->published_at]);
            }

            $english->forceFill(['auto_source_hash' => $source])->save();
            // Re-read: a new row gets its "draft" status from the column default, and the hash must be of the text
            // exactly as stored (the body passes HtmlSanitizer on the way in).
            $english->refresh();
            $english->forceFill(['auto_output_hash' => self::hash($english)])->save();

            if ($english->status !== TranslationStatus::Published) {
                $this->workflow->publishNow(
                    $english,
                    self::account(),
                    $arabic->published_at?->isFuture() ? $arabic->published_at : null,
                );
            }
        });

        return $created ? 'translated and published' : 'translated again';
    }

    /** True while the row is exactly what the machine wrote; false for a person's text or a machine text a person edited. */
    public static function isMachineMade(ArticleTranslation $translation): bool
    {
        return $translation->auto_output_hash !== null && hash_equals($translation->auto_output_hash, self::hash($translation));
    }

    public static function hash(ArticleTranslation $translation): string
    {
        return hash('sha256', json_encode(
            array_map(fn ($v) => trim((string) $v), $translation->only(GeminiTranslator::FIELDS)),
            JSON_UNESCAPED_UNICODE,
        ));
    }

    /** The system account, created the first time it is needed. Never signs in: its password is random and discarded. */
    public static function account(): User
    {
        $user = User::query()->where('email', self::ACCOUNT_EMAIL)->first() ?? new User([
            'email' => self::ACCOUNT_EMAIL,
            'name' => 'Auto-translation',
            'name_ar' => 'الترجمة الآلية',
            'password' => Str::random(64),
        ]);

        // Only what publishing an English version needs (edit any story, approve, unpublish); kept so on every use.
        $user->forceFill(['role' => Role::Journalist, 'is_active' => true]);
        $user->choosePermissions([Permission::EditAllStories, Permission::PublishStories]);
        if ($user->isDirty()) {
            $user->save();
        }

        return $user;
    }
}
