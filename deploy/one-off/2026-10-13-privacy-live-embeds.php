<?php

/*
 * One-off for the 2026-10-13 update (journalist sections + live broadcasts).
 *
 * Adds the "Live video" section, and the amended second bullet of "What we do not collect", to the privacy page that
 * is ALREADY in the database, in both languages. PageSeeder never touches a page that exists, so the deploy alone
 * cannot do this; database/pages/pages.json carries exactly the same text for fresh installs.
 * Do it before the first broadcast goes live: once a reader presses play, the old text ("our pages do not currently
 * load advertising or tracking code from other companies") is no longer true.
 *
 * It writes to the production database, so run it only with the owner's go-ahead. From the project folder in Git Bash:
 *
 *   ssh wxr46gn9f7ld@92.205.88.46 'cd ~/sisi.news && DRY_RUN=1 php' < deploy/one-off/2026-10-13-privacy-live-embeds.php   # report only
 *   ssh wxr46gn9f7ld@92.205.88.46 'cd ~/sisi.news && php' < deploy/one-off/2026-10-13-privacy-live-embeds.php             # write
 *
 * Safe to run again: each change is made only while it is missing, so a second run reports "Nothing to change".
 * It saves through the PageTranslation model, so the body passes HtmlSanitizer and the footer cache is cleared.
 * It works on the code that is live before the deploy as well as after it (it only uses the Page models).
 * Exit code 1 when something has to be checked by hand (the page or an anchor was not found); nothing is half-written
 * in that language then, but read the printed lines.
 *
 * tests/Feature/Deploy/PrivacyLiveEmbedsOneOffTest.php loads this file with SISI_ONE_OFF_LIBRARY_ONLY defined, so
 * only the functions below are declared and nothing runs.
 */

use App\Models\Page;
use App\Models\PageTranslation;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

if (! function_exists('sisi_privacy_live_embeds_texts')) {
    /**
     * The approved wording, per language. `anchor` is the paragraph the section goes after; `next_heading` is the
     * heading it goes before when the anchor paragraph has been edited away.
     *
     * @return array<string, array{anchor: string, next_heading: string, heading: string, section: string, old_bullet: string, new_bullet: string}>
     */
    function sisi_privacy_live_embeds_texts(): array
    {
        return [
            'en' => [
                'anchor' => 'Your browser keeps any text size you choose in its local storage, on your own device. We never receive it.',
                'next_heading' => 'What we do not collect',
                'heading' => 'Live video',
                'section' => implode("\n", [
                    '<h2>Live video</h2>',
                    '<p>The broadcasts on our Live page are hosted by YouTube (owned by Google) or Vimeo, not by us. Until you press play, the page loads nothing from either service: the player you see is a placeholder from our own server, so neither service learns that you opened the page.</p>',
                    '<p>When you press play, your browser loads that service’s player directly from it and the broadcast starts. From then on the service receives what any website receives from your browser, such as your IP address and browser and device details, and that the video was played on sisi.news; it may also store information in your browser and record how you use the player. We use YouTube’s privacy-enhanced mode (youtube-nocookie.com), in which, YouTube says, videos watched on our site do not influence what you see on YouTube itself, and Vimeo’s “do not track” setting (dnt=1), which, Vimeo says, stops its player tracking your session, including with cookies. If you are signed in to Google or Vimeo in the same browser, the service may link the viewing to your account. Any advertising inside a player comes from that service, not from us. Each service’s own privacy policy governs what it does. Neither service sends us anything that identifies you; for a broadcast on our own channel it may show us overall figures, such as the number of viewers.</p>',
                    '<p>To share nothing with these services, do not press play or follow the links to watch on YouTube or Vimeo.</p>',
                ]),
                'old_bullet' => 'No advertising, advertising cookies or analytics and tracking services. Our pages do not currently load advertising or tracking code from other companies; if you click a share link, that service’s own policy applies.',
                'new_bullet' => 'No advertising, advertising cookies or analytics and tracking services of our own. Our pages do not load advertising or tracking code from other companies, with one exception that you control: a YouTube or Vimeo player, which loads only after you press play (see “Live video” above). If you click a share link, or a link to watch a broadcast on YouTube or Vimeo, that service’s own policy applies.',
            ],
            'ar' => [
                'anchor' => 'ويحتفظ متصفحك بحجم النص الذي تختاره للمواد في تخزينه المحلي على جهازك أنت، ولا يصلنا هذا الإعداد.',
                'next_heading' => 'ما لا نجمعه',
                'heading' => 'البث المباشر',
                'section' => implode("\n", [
                    '<h2>البث المباشر</h2>',
                    '<p>البث المعروض في صفحة البث المباشر تستضيفه خدمة يوتيوب (المملوكة لشركة جوجل) أو خدمة فيميو (Vimeo)، لا خوادمنا. وقبل أن تضغط زر التشغيل لا تحمّل الصفحة أي شيء من هاتين الخدمتين، فالمشغّل الذي تراه واجهة ثابتة يرسلها خادمنا، ولا تعلم أيٌّ من الخدمتين أنك فتحت الصفحة.</p>',
                    '<p>وحين تضغط زر التشغيل يجلب متصفحك مشغّل الخدمة منها مباشرةً ويبدأ البث. ومنذ تلك اللحظة تتلقى الخدمة ما يتلقاه أي موقع من متصفحك، كعنوان IP وبيانات المتصفح والجهاز، وتعرف أن المقطع شوهد عبر sisi.news، وقد تخزّن معلومات في متصفحك وتسجّل كيفية استخدامك للمشغّل. ونستخدم «وضع الخصوصية المحسّن» في يوتيوب (youtube-nocookie.com)، وبحسب يوتيوب لا تؤثر المقاطع التي تشاهدها على موقعنا فيما يُعرض عليك في يوتيوب نفسه، ونستخدم إعداد «عدم التتبّع» في فيميو (dnt=1)، وبحسب فيميو يمنع هذا الإعداد مشغّلها من تتبّع جلستك، بما في ذلك عبر ملفات تعريف الارتباط. وإذا كنت مسجّلًا الدخول إلى جوجل أو فيميو في المتصفح نفسه، فقد تربط الخدمة المشاهدة بحسابك. وأي إعلان يظهر داخل المشغّل تضعه الخدمة لا نحن. وتحكم سياسة الخصوصية الخاصة بكل خدمة ما تفعله بهذه البيانات. ولا تُرسل إلينا أيٌّ من الخدمتين ما يدل على هويتك، وإن كان البث من قناتنا فقد تُطلعنا على أرقام إجمالية فحسب، كعدد المشاهدين.</p>',
                    '<p>وإن أردت ألّا تشارك أي بيانات مع هاتين الخدمتين، فلا تضغط زر التشغيل ولا تفتح روابط مشاهدة البث على يوتيوب أو فيميو.</p>',
                ]),
                'old_bullet' => 'لا إعلانات ولا ملفات تعريف ارتباط إعلانية ولا خدمات تحليل أو تتبّع. ولا تحمّل صفحاتنا حاليًا شيفرات إعلانية أو شيفرات تتبّع من شركات أخرى. وإذا نقرت على رابط مشاركة انتقلت إلى تلك الخدمة وانطبقت عليك سياستها.',
                'new_bullet' => 'لا إعلانات ولا ملفات تعريف ارتباط إعلانية ولا خدمات تحليل أو تتبّع خاصة بنا. ولا تحمّل صفحاتنا شيفرات إعلانية أو شيفرات تتبّع من شركات أخرى، إلا في حالة واحدة يعود القرار فيها إليك: مشغّل يوتيوب أو فيميو، ولا يُحمَّل إلا بعد أن تضغط زر التشغيل (انظر «البث المباشر» أعلاه). وإذا نقرت على رابط مشاركة، أو على رابط لمشاهدة البث على يوتيوب أو فيميو، انتقلت إلى تلك الخدمة وانطبقت عليك سياستها.',
            ],
        ];
    }

    /** A regex fragment for $text in which any run of whitespace matches any other (the panel's editor reflows HTML). */
    function sisi_privacy_live_embeds_text_pattern(string $text): string
    {
        return implode('\s+', array_map(fn (string $word): string => preg_quote($word, '~'), preg_split('/\s+/u', trim($text))));
    }

    /**
     * Adds the "Live video" section after the text-size paragraph (or, if that paragraph was edited, before the
     * "What we do not collect" heading) and swaps the old second bullet for the new one. Each change is made only
     * while it is missing, so the result of a second pass is the same body.
     *
     * @return array{0: string, 1: list<string>} the new body and one note per change, "CHECK BY HAND" when something was not found
     */
    function sisi_privacy_live_embeds_apply(string $body, string $locale): array
    {
        $text = sisi_privacy_live_embeds_texts()[$locale] ?? null;
        if ($text === null) {
            return [$body, ["CHECK BY HAND: no wording for the language \"{$locale}\""]];
        }

        $notes = [];

        if (preg_match('~<h2\b[^>]*>\s*'.sisi_privacy_live_embeds_text_pattern($text['heading']).'\s*</h2>~u', $body) === 1) {
            $notes[] = 'the "'.$text['heading'].'" section is already there';
        } elseif (preg_match('~<p\b[^>]*>\s*'.sisi_privacy_live_embeds_text_pattern($text['anchor']).'\s*</p>~u', $body, $match, PREG_OFFSET_CAPTURE) === 1) {
            $end = $match[0][1] + strlen($match[0][0]);
            $body = substr($body, 0, $end)."\n".$text['section'].substr($body, $end);
            $notes[] = 'added the "'.$text['heading'].'" section after the text-size paragraph';
        } elseif (preg_match('~<h2\b[^>]*>\s*'.sisi_privacy_live_embeds_text_pattern($text['next_heading']).'\s*</h2>~u', $body, $match, PREG_OFFSET_CAPTURE) === 1) {
            $start = $match[0][1];
            $body = substr($body, 0, $start).$text['section']."\n".substr($body, $start);
            $notes[] = 'added the "'.$text['heading'].'" section before "'.$text['next_heading'].'" (the text-size paragraph was not found)';
        } else {
            $notes[] = 'CHECK BY HAND: neither the text-size paragraph nor the "'.$text['next_heading'].'" heading was found, so the "'.$text['heading'].'" section was NOT added';
        }

        $old = '~(<li\b[^>]*>\s*(?:<p\b[^>]*>\s*)?)'.sisi_privacy_live_embeds_text_pattern($text['old_bullet']).'(\s*(?:</p>\s*)?</li>)~u';

        if (preg_match('~'.sisi_privacy_live_embeds_text_pattern($text['new_bullet']).'~u', $body) === 1) {
            $notes[] = 'the amended "'.$text['next_heading'].'" bullet is already there';
        } elseif (preg_match($old, $body) === 1) {
            $body = (string) preg_replace_callback($old, fn (array $m): string => $m[1].$text['new_bullet'].$m[2], $body, 1);
            $notes[] = 'replaced the second "'.$text['next_heading'].'" bullet';
        } else {
            $notes[] = 'CHECK BY HAND: the old second "'.$text['next_heading'].'" bullet was not found (edited in the panel?), so it was NOT replaced';
        }

        return [$body, $notes];
    }

    /**
     * Applies the change to every stored language of the privacy page. With $write false nothing is saved.
     *
     * @return array{0: list<string>, 1: bool} the report lines, and whether everything was found
     */
    function sisi_privacy_live_embeds_run(bool $write): array
    {
        $page = Page::query()->where('slug', 'privacy')->with('translations')->first();
        if ($page === null) {
            return [['CHECK BY HAND: there is no page with the slug "privacy". Nothing was changed.'], false];
        }

        $lines = [];
        $complete = true;

        DB::transaction(function () use ($page, $write, &$lines, &$complete): void {
            foreach ($page->translations->sortBy(fn (PageTranslation $t): string => $t->locale->value) as $translation) {
                $locale = $translation->locale->value;
                $before = (string) $translation->getRawOriginal('body');
                [$after, $notes] = sisi_privacy_live_embeds_apply($before, $locale);

                foreach ($notes as $note) {
                    $complete = $complete && ! str_starts_with($note, 'CHECK BY HAND');
                }

                if ($after === $before) {
                    $outcome = 'Nothing to change.';
                } elseif ($write) {
                    // Through the model: the body mutator runs HtmlSanitizer, and saving clears the footer cache.
                    $translation->body = $after;
                    $translation->save();
                    $outcome = 'Saved.';
                } else {
                    $outcome = 'Would save (dry run, nothing written).';
                }

                $lines[] = "privacy [{$locale}]: ".implode('; ', $notes).'. '.$outcome;
            }
        });

        return [$lines, $complete];
    }
}

if (defined('SISI_ONE_OFF_LIBRARY_ONLY')) {
    return;
}

if (! is_file('vendor/autoload.php') || ! is_file('bootstrap/app.php')) {
    fwrite(STDERR, "Run this inside the application folder: ssh <host> 'cd ~/sisi.news && php' < deploy/one-off/2026-10-13-privacy-live-embeds.php\n");
    exit(1);
}

require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$write = in_array(getenv('DRY_RUN'), [false, '', '0'], true);
echo $write ? "Updating the privacy page (write mode).\n" : "DRY RUN: reporting only, nothing is written.\n";

[$lines, $complete] = sisi_privacy_live_embeds_run($write);
foreach ($lines as $line) {
    echo $line, "\n";
}

exit($complete ? 0 : 1);
