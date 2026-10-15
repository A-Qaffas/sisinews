# Trust pages: owner's notes

For the owner. The copy is in `database/pages/pages.json` (five pages, Arabic and English). **It has not been read by a lawyer or by a native Egyptian editor.** It was written by one agent and then audited by a second, independent reviewer (section 5 lists what the audit changed). Nothing in it names the owner, a company, an address, a place of business, a founding date, awards, staff numbers or independence, because none of that is known. Where the pages need those facts they say the owner "will publish" them (see section 3).

The pages are written to be true of the site as built (checked against the code on 12 October 2026, and the cookie facts by running the app) and to promise only what a small newsroom can keep. Each promise below is a **commitment**: once the page is live, readers can hold the outlet to it, so someone must own each one.

| Page | Slug | EN words | AR words |
|---|---|---|---|
| About us / من نحن | `about` | 516 | 392 |
| Editorial standards / المعايير التحريرية | `standards` | 691 | 600 |
| Corrections policy / سياسة التصحيح | `corrections` | 514 | 376 |
| Privacy policy / سياسة الخصوصية | `privacy` | 684 | 590 |
| Terms of use / شروط الاستخدام | `terms` | 586 | 439 |

(Whitespace-separated words, tags removed. `[[contact]]` counts as one word and renders as two or three; English Standards and Privacy stay under 700 even with it expanded. If you add text, keep each body between 350 and 700 words.)

**If the pages are already in a database**, the seeder will not change them (it only creates a page whose slug is missing). Edit the live pages in the CMS, or delete the five rows and seed again, to bring them in line with this file.

## 1. Commitments the outlet must be able to keep

**About**
- No story goes live without an editor's approval. (True today: the workflow enforces it. Note that the policy lets an editor or an admin approve their own story; if you mean "a second pair of eyes", that is a rule to adopt, not something the software forces.)
- Stories without a named author are credited to the sisi.news Desk and an editor answers for them.
- "No advertising, no analytics or tracking, no reader accounts, no comments, free to read." True of the build now. If any of this changes (the roadmap plans cookie-less analytics and ads), the About and Privacy pages must change first. Since 13 October 2026 the Live page can embed YouTube/Vimeo players, loaded only after the reader presses play; Privacy says so ('Live video', and the amended second bullet of 'What we do not collect'). About's "no analytics or tracking services" still holds: the site runs none of its own.
- "We reply to error reports and to requests about personal data." Someone has to read the contact mailbox every working day.
- Ownership and funding "will be published on this page" (see section 3).
- The About page does not say where the outlet is based. Add it (for example "based in Cairo") only when it is true and the owner agrees.

**Editorial standards**
- Verification: a fact is checked against a document, a first-hand witness, an official record or more than one independent source. Numbers, dates, names and titles are checked.
- **Analysis and opinion are labelled clearly.** The CMS has no article-type field, so this needs a written convention (a label in the headline or first line, or a fixed tag) or a small build change.
- Right of reply before publication, with reasonable time. The one exception (publishing first when advance notice would endanger someone or let evidence be destroyed) is stated on the page.
- Every picture carries a credit and licence, and cropping of a licensed picture is disclosed. The launch pictures already follow this; new uploads depend on editors writing the caption line by hand (no photo desk yet).
- AI: every AI-assisted draft is checked by an editor before publication; a material AI contribution is stated on the story (again needs a convention, there is no field); AI-generated images, audio or video are never presented as real; unpublished confidential material and anything that could identify a protected source never goes into AI tools. Staff need a short written rule saying which tools are allowed.
- **The statement that the first explainers were "drafted with the help of AI tools, checked against published sources and reviewed by an editor before publication".** The drafting and the source checks are true: `docs/LAUNCH-CONTENT.md` records that the stories were written with a model and their facts checked against sources. **The editor review is not yet true**: the same document says no Egyptian editor has read them. This is a launch gate. An editor must review all twelve stories (both languages) before the pages go live, or the sentence (in About and Standards) must be removed.
- Conflicts of interest: staff tell their editor first; no payment or gifts for coverage; paid or sponsored material is labelled; the interests of the site's owners or funders are disclosed in any story where they are directly involved. This is a rule to adopt, not a feature.
- Sources: a confidential source is never identified unless they agree. The page also says honestly that the outlet cannot control what a court or the authorities may demand, and that email is not fully secure. Section 2 asks the lawyer what protection is really possible.

**Corrections**
- Acknowledge a report (when the reader gave a way to reply), have an editor check it, correct as soon as the error is confirmed, and add a **visible, dated note** to the story, on top for serious errors and at the end for minor ones, in the language version that was wrong.
- A complaint about the handling is reviewed by a second editor. This needs at least two editors, or change the sentence to name the editor-in-chief.
- Quiet fixes are limited to typing slips that do not change meaning. Everything else gets a note.
- The site has no correction-note field, "Updated" time or public corrections log yet (ROADMAP Phase 1). Until then editors write the note into the story text by hand, so write down a short procedure. The page deliberately does not promise a corrections list.
- The page shows an example note ("3 March ... 12 km ... 21 km"). It now says in so many words that the example is invented; do not leave a real-looking correction in its place.

**Privacy** (facts checked in the code; the points in section 4 are the part to verify on the live server)
- Newsletter: stores email, language, an unsubscribe code, and the subscribe and unsubscribe dates. Nothing is sent yet; the page says so and promises that every issue will carry an unsubscribe link once sending starts. **When sending starts, edit the sentence "which we have not started sending".**
- Cookies (checked by running the app): every public page, including the feed and sitemap, sets exactly two cookies: the session cookie (HttpOnly) and `XSRF-TOKEN` (readable by scripts, a copy of the form-protection token). Both last 120 minutes and are renewed on each page, so "about two hours without activity" is accurate while `SESSION_LIFETIME` stays at 120. The page names `XSRF-TOKEN` but not the session cookie, whose name comes from `APP_NAME`: with `APP_NAME="sisi.news"` (as in `.env.example`) it is `sisinews-session`. Check the two names in the browser after launch.
- The session record holds a random id, the list of stories opened (for the view counter), and the last page address. With the `database` session driver it also holds the IP address and browser type, which is why the page says it "may also" record them. The counter itself stores only daily totals per story.
- What really happens when cookies are blocked: the site works; stories may be counted again; and the newsletter form may not show its confirmation message (it is stored in the session) or, in an old browser that does not send the `Sec-Fetch-Site` header, may be refused with a "Page expired" error. In a current browser the subscription still goes through. The page says only "may not confirm your sign-up, or may refuse it".
- The page also discloses things the first draft left out: the web server's logs include the search words in the address; the site keeps short-lived request counters tied to the IP address (the rate limits on search and the newsletter form); and the text-size choice is kept in the browser's local storage and never sent to us.
- **Session length "about two hours"** is the config default. Check the production `.env`.
- **Server logs.** The page no longer states a number. It says logs are kept "only as long as we need them for security and fault-finding, and no longer". That has to be true: find out how long cPanel keeps the raw web-server logs, and how long the application's own `storage/logs` are kept. Laravel's `LOG_STACK` defaults to `single`, which never rotates; use `daily` with a `LOG_DAILY_DAYS` you can defend. When you know the real figure, you may add it to the page.
- Replies to access, correction and deletion requests: "we aim to reply within 30 days". Only admins can delete subscribers in the CMS. The lawyer should confirm whether a shorter statutory period applies (question 4).
- Backups: the page says copies may remain in backups for about two weeks. That matches the nightly script in `docs/DEPLOYMENT.md` (14 days), **if it is installed as written**. Copies you take off the server by hand must be deleted on the same schedule, or the sentence is wrong.
- "We will update this page first" before adding analytics, advertising or reader accounts.
- Live video: nothing from YouTube or Vimeo is in the page until play (LivePageTest::test_the_initial_html_loads_nothing_from_the_video_services). After play the iframe is youtube-nocookie.com or player.vimeo.com with dnt=1, with the referrer limited to the origin. The 'Live video' section and the amended 'What we do not collect' bullet describe this. A new provider (Vimeo event links are refused for this reason), autoplay before a click, or a remote thumbnail would make them false. The English Privacy body is now about 250 words over the 700-word budget, on purpose. An already-installed site gets the text from `deploy/one-off/2026-10-13-privacy-live-embeds.php` (see `deploy/README.md`), because PageSeeder never changes an existing page.
- No sale or rental of personal data, and no use of email addresses for anyone's marketing.
- Sessions are deleted only by Laravel's built-in chance-based clean-up (2 percent of requests), not by a scheduled job; on a quiet site old session rows can stay for days, although they cannot be used. The page says the server's "routine clean-up" deletes them.

**Terms**
- Permission to quote a short extract with credit and a link, and to read the RSS feed in a feed reader. These are permissions the owner grants; confirm them.
- Tips and photographs: the sender is not named without agreement.
- Material changes to the terms are announced on the site.

## 2. For the lawyer (questions, not advice)

**Data protection**
1. Does the Personal Data Protection Law (Law 151 of 2020) and its executive regulations, if in force, apply to a newsletter list, session records and web logs? Is any registration, licence or data protection officer needed? (ROADMAP legal question 4.)
2. Are an IP address and a session id personal data here? Are the session cookie and `XSRF-TOKEN` "strictly necessary", so no cookie notice is needed for Egyptian visitors, and what about EU and UK visitors?
3. Addresses are stored without a confirmation step. What consent record is needed before the first newsletter is sent, and what must each issue contain?
4. The site is hosted with a company abroad. Do transfer rules apply to the list and the logs? Is "we aim to reply within 30 days" acceptable, or does a shorter period apply? Is marking an address "unsubscribed" (not deleting it) acceptable until erasure is asked for?
5. What age threshold should the "Children" section use in Egypt?

**Media law**
6. Does a news website need a licence or registration (Law 180 of 2018), and must the About page name an editor-in-chief or a licence number? (ROADMAP legal question 1.)
7. Do the correction and right-of-reply promises meet or conflict with any statutory deadline, prominence or form? Does "we will consider publishing a reply" fall short of a legal duty to publish one?
8. What can the outlet truthfully promise a confidential source (court orders, prosecutors, what the host's logs could reveal)? Is the sentence about courts and the authorities, with the email warning, enough?
9. Do the "we do not publish rumour as fact" and AI-disclosure statements affect exposure under the false-news and cybercrime provisions (Law 175 of 2018)? (ROADMAP legal question 3.)
10. Copyright: is quoting a short extract with credit within Egyptian exceptions? Is the "no scraping at volume, including AI training" term enforceable? Several launch photographs show modern buildings with no freedom of panorama (see LAUNCH-CONTENT section 1). **New:** the launch explainers were drafted with AI; can the outlet claim copyright in them, as the Terms say the stories "belong to us"?
11. Terms: is the limitation of liability valid as worded? Is "the laws of the Arab Republic of Egypt" enough without a courts clause? If the Arabic and English texts ever differ, which governs? (The pages say nothing about precedence; that is for the lawyer to add.)

## 3. Placeholders the owner must decide

1. **Contact email.** Set `SITE_CONTACT_EMAIL` in `.env`. Never put an address in the page data. Until it is set, every "write to" line renders as "write to the editorial team", which gives readers no way to write, so set it before launch, and use a mailbox somebody reads daily because the pages promise replies.
2. **Legal entity, address and ownership.** About ("Who is behind the site"), Privacy ("Who is responsible") and Terms (first paragraph) each say the owner's details "will be published". ROADMAP section 6 treats publishing ownership and funding as **an open owner decision**, so this is a promise made on your behalf. Either supply the facts and replace those sentences, or, if you are not ready, replace the About sentence with: *"This page does not yet say who owns and operates sisi.news or how the site is funded."* (Arabic: «لا تبيّن هذه الصفحة بعد من يملك موقع sisi.news ويشغّله ولا كيف يُموَّل.») and make the Privacy and Terms sentences match. The copy says "owner and operator" and never "company", because the legal form is not known.
3. **Where the outlet is based.** Not stated anywhere. Add it if you want it, once true.
4. **Applicable law.** Currently only "the laws of the Arab Republic of Egypt". Decide on a courts or forum clause and language precedence after the lawyer's answer.
5. **AI policy.** Confirm the wording in Editorial standards, name the tools staff may use, and decide whether to label every AI-assisted story or only those where the contribution is material (as written).
6. **Numbers and promises on Privacy:** the 30-day reply, "about two hours" session, "about two weeks" in backups.
7. **Masthead.** The wordmark stays Latin (`sisi.news`) in Arabic text; the Arabic desk name «تحرير سيسي نيوز» comes from `launch-content/stories.json`. If an Arabic masthead is chosen, replace it in the pages.

## 4. To verify on the live server after deploy

- Cookie names in the browser: `XSRF-TOKEN` and the session cookie (expected `sisinews-session`); both about two hours.
- `SESSION_DRIVER` and `SESSION_LIFETIME` in the production `.env`. `.env.example` says `SESSION_DRIVER=file` while the config default is `database`; the page works with either, but only the database driver stores IP and browser type.
- How long cPanel keeps raw access logs, and the application log setting (above).
- The backup cron is installed and prunes at 14 days, and off-server copies follow the same rule.
- That the page "Last updated" line looks right (the page template shows the translation's last-saved date, which will be the day the pages were created).

## 5. Independent review (12 October 2026): what changed

Checked sentence by sentence against the routes, controllers, newsletter code, view counter, session and middleware configuration, the layout and the launch-content documents. Changes made:

- **Location.** "based in Egypt" (About, body and meta description) removed; nothing in the repository establishes where the outlet is based.
- **Invented company.** "the company that owns and operates the site" replaced by "the owner and operator" (Arabic «الجهة المالكة للموقع والمشغِّلة له») in About, Privacy and Terms.
- **AI disclosure.** "prepared with AI assistance and edited by our team" replaced by "drafted with the help of AI tools, checked against published sources and reviewed by an editor before publication" (About and Standards). "Only as an aid to our journalists" became "to help prepare stories", because the launch pieces have no journalist author.
- **Cookies.** Rewritten from "a session cookie with a companion security cookie" to exactly what is set (a session cookie and `XSRF-TOKEN`), with what each does, what the session record holds, and the real effect of blocking them. The claim that the form "relies on the cookies for protection" was wrong for current browsers (verified: a POST with `Sec-Fetch-Site: same-origin` and no cookie is accepted and stored; without that header it gets a 419).
- **Retention.** The unenforced "no more than a month" for logs is now a principle, not a number. The two-hour claim now says the record is deleted by the server's routine clean-up. The newsletter line no longer contradicts itself (the address is kept until erasure is requested, even after unsubscribing). Backups: "briefly" became "about two weeks".
- **Disclosures added.** Hosting provider runs the web server; search words appear in logs; short-lived request counters tied to IP; the text-size setting lives in local storage.
- **Sources.** The unconditional confidentiality promise now says the outlet cannot control what a court or the authorities may demand.
- **Smaller fixes.** About newsletter bullet now lists the dates stored; the correction example is labelled invented; "say so in your reply" no longer assumes a reply; "share buttons" are plain links, so now "share links"; the liability carve-out is worded as a limit on the exclusion.
- **Arabic.** Calques and ambiguities fixed (القارئ الجديد على موضوع ما, «تمهيدية», الإشاعة → الشائعة, «ونذكر ذلك» used twice with unclear referent, مموِّليه with the shadda on the wrong letter, «سرت عليك سياستها», «تتبعية», «نستهدف الرد», «نسخ منها», «من الادعاء», «قضية مستمرة», «بيّن لنا هل»); register raised («لا بأس» → «يجوز»); the cookie terms made consistent with the glossary (ملفات تعريف الارتباط everywhere).
- **Bidi.** Where a sentence or clause ends in the Latin name `sisi.news` followed by a punctuation mark, an LRM (U+200E) follows the punctuation, the same convention as the site footer (`lang/ar/layout.php`). Three places: the Terms body and two meta descriptions. Stored as `‎` in the JSON.
- **Length.** The Privacy page in English grew past 700 words during the fixes and was cut back to 684.

Known cosmetic point, not fixable in the copy: in Arabic, where `[[contact]]` ends a sentence and renders as an e-mail link (shown left to right), the full stop sits at the left end of the address, which is correct right-to-left behaviour but can look like a leading dot. If it bothers the Arabic desk, the render step (`App\Support\PageBody`) is the place to deal with it.

## 6. Notes for whoever maintains the copy

- **`[[contact]]`** is replaced at render time by a mailto link or by "the editorial team" / "فريق التحرير". Sentences were written to read correctly either way: never put the token inside a link, start a sentence with it, or attach an Arabic prefix to it.
- Bodies use only `p, h2, ul, ol, li, strong, blockquote` and one `a` (to creativecommons.org). All ten bodies pass `App\Support\HtmlSanitizer` with their text unchanged (it adds `rel="noopener noreferrer nofollow"` to the one external link, and on Windows it turns line feeds into CR LF; both are harmless).
- There are no links between the five pages (relative links are not allowed in the data); they refer to each other by title. If you retitle a page, change the references too: English "Editorial standards", "Corrections policy", "Privacy policy"; Arabic «المعايير التحريرية»، «سياسة التصحيح»، «سياسة الخصوصية».
- The page template itself shows "Last updated <date>" under every page title, so the pages do not need a date of their own.
- Arabic terms, kept the same on all pages: story = مادة, explainer = مادة شارحة, journalist = صحفي, editor = محرّر, editorial team = فريق التحرير, correction = تصحيح, update = تحديث, clarification = توضيح, newsletter = النشرة البريدية, language version = نسخة لغوية, personal data = البيانات الشخصية, server logs = سجلات الخادم, cookies = ملفات تعريف الارتباط (session cookie = ملف تعريف ارتباط للجلسة), hosting provider = مزوّد الاستضافة, owner = الجهة المالكة / المالك, sources = المصادر. Second person is singular throughout, as in the site's own interface text.
- For structure the first author looked at what established outlets publish (standards, corrections, privacy pages); the Reuters and Guardian pages could not be fetched, so the structure comes from general practice. No outside text was copied.
