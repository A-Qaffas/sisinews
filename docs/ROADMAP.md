# sisi.news roadmap: from v1 to a credible international outlet

*Prepared 12 October 2026 for the owner and management. Plain English first; technical detail is mostly in sections 3 and 4. Not legal advice.*

**How to read this.** Anything I am not certain of is marked **[check]**. Statements of opinion are marked **[judgment]**. Every number is a rough range, not a forecast or a statistic.

**What exists today (checked in the code on 12 October 2026).** A bilingual Arabic/English site with six sections, home page, article pages, search, RSS, sitemap and a newsletter sign-up that stores addresses but sends nothing. Staff move each language version through draft, review and publish, with scheduling and an audit trail. It runs on one shared GoDaddy server. Missing: CDN, background jobs, email, analytics, video, comments, author pages, topic pages. About, Privacy and Terms are in progress, not yet in the code. **The code is not yet in version control (no Git repository).**

---

## 1. Reality check

**What CNN and the BBC actually are.** Not websites. Organisations with thousands of journalists, bureaus in dozens of countries, 24-hour television, decades of reputation and in-house lawyers, on budgets of hundreds of millions of dollars a year or more (order of magnitude, rough). The website is their shop window.

**What the software does.** The site built this week is a solid, fast, secure foundation with a real editorial workflow. Technically it is close to what a serious regional outlet needs on day one. But software is perhaps a tenth of the problem **[judgment]**. The rest is:

| What it takes | What it means in practice | Can software solve it? |
|---|---|---|
| A newsroom | Reporters, editors, translators, photo, video and social staff, covering long hours | No; software only makes them faster |
| Sourcing | People who take your calls; paid news-agency ("wire") subscriptions | No |
| Trust | Years of being right, correcting openly, being seen as independent | Partly: transparency pages, corrections, bylines |
| Distribution | Google, social platforms, newsletters, alerts | Partly: technical eligibility, then daily editorial habit |
| Money | 12 to 24 months of spending before revenue matters (rough) | No |
| Time | Those brands took decades | No |

**A realistic 12-month ambition.** Not "beat CNN". Instead: *be the first place serious readers check for Egypt and its neighbourhood, in excellent Arabic and credible English.* By month 12 that could look like:

- A steady flow of original reporting, not rewrites: roughly 15 to 30 original stories a day across both languages, depending on staff (rough).
- Stories appearing in Google News and Google Discover (eligible, never guaranteed).
- A daily newsletter in each language that people actually open.
- One or two subjects where other outlets cite you as the reference.
- Every mistake corrected publicly and visibly.

**Where you can actually win.**

- **Arabic quality.** Well-edited, original, clearly sourced Arabic journalism is scarcer than it should be **[judgment]**. Be the best-written Arabic news on the web.
- **Access and context.** International outlets fly in; you live here and know the people and the history.
- **Speed on Egyptian stories.** First with *verified* facts, in both languages.
- **Trust.** Published ownership, clear standards, visible corrections. The hardest asset to build and the most valuable.
- **Niche franchises.** Egypt's economy (currency, inflation, debt, energy, tourism); the Suez Canal and Red Sea shipping; regional affairs (Sudan, Libya, Gaza, Nile water). A sharp franchise beats a general site.

**The uncomfortable point: the name.** Competing internationally means competing on trust, and perceived independence is the core of trust. Outside Egypt, "sisi.news" will be read as connected to President Abdel Fattah el-Sisi, whatever the intent, and every story will be judged through that lens. This is the owner's decision (section 6), and far cheaper to make now than after money goes into the brand.

---

## 2. Phased roadmap

Effort is in **developer-days for one experienced Laravel developer** (rough). Editorial and legal work, usually the real bottleneck, is noted separately.

### Phase 0: launch hardening (this week, about 5 to 6 developer-days)

| Deliverable | Why | Effort |
|---|---|---|
| Put the code in a private Git repository (GitHub or GitLab) | Today it lives only on one laptop; one disk failure loses it | Half a day |
| Trust pages: About, Ownership and funding, Editorial standards, Corrections policy, Contact, Privacy, Terms | Readers, Google and partners look for these first | 1 day tech, plus editorial and lawyer time |
| Two-step login for staff (a code from a phone app; no email needed) | A stolen password would let anyone publish fake news under your name. Filament supports it | Half to 1 day |
| Nightly off-server backups of database and images, plus one test restore | A backup never restored is only a hope | 1 day |
| Uptime and error alerts to phones (free tiers exist) | Know the site is down before readers tell you | Half a day |
| Production check: debug off, HTTPS forced, caches built; registrar lock and two-step login on the GoDaddy account | Stops leaking internals and prevents domain hijacking | Half a day |
| Google Search Console and Bing Webmaster Tools; submit the sitemap | Free; shows how search engines see the site | Half a day |
| Add the `max-image-preview:large` robots tag | Large images in Google Discover need it; your 1280 px hero images meet the size guideline when the upload is big enough **[check current Google docs]** | 1 hour |
| `contact@` and `corrections@` mailboxes; reserve social handles in both languages | Readers need a way to reach you | Admin time |
| Recommended: Cloudflare (free plan) in front of the site | Shields the server, caches images and styles, absorbs attacks. Needs DNS moved to Cloudflare | Half a day |

### Phase 1: first 30 days (about 15 to 25 developer-days)

| Deliverable | Why | Effort |
|---|---|---|
| Author pages: bio, photo, role, Arabic and English names; every byline links to one | Readers and Google both ask "who wrote this, and why trust them?" | 2 to 3 days |
| Corrections: a correction note on the story, an "Updated" time, a public corrections log | The strongest trust signal you can ship | 2 to 3 days |
| Revision history: every saved version of the text, who changed what | Today only status changes are recorded, not edits to the words. Needed for disputes | 2 to 3 days |
| Google News sitemap (stories from the last two days) and a Publisher Center profile | Helps Google find news fast. Google decides inclusion; there is no application **[check current process]** | 1 to 2 days |
| Newsletter that sends: an email service, double opt-in, a daily brief per language | Email is the audience you own. *Double opt-in*: the subscriber clicks a confirmation link, which protects consent and delivery. Ask existing addresses to confirm | 4 to 6 days |
| Background job runner (a "queue") driven by cron on the current host | Email, image resizing and social posting must not slow pages. Is every-minute cron allowed? **[check]** | 1 day |
| Privacy-friendly analytics (cookie-less tools such as Plausible, Fathom, Matomo or Cloudflare Web Analytics) | You cannot manage what you do not measure; cookie-less usually means no consent banner **[check with lawyer]** | 1 day |
| Distribution playbook: X, Facebook, Instagram, TikTok, WhatsApp Channel, Telegram channel | Mostly editorial: who posts what, when, in which language. Auto-posting to Telegram is simple; WhatsApp Channels are posted by hand as far as I know **[check]**. Share cards exist | 1 to 2 days |
| Topic pages (tags are labels today, with no page of their own) | Hub pages such as "Suez Canal" or "Egyptian pound" rank in search and anchor your franchises | 2 days |
| Staff-editable static pages in the CMS | Editors, not developers, keep policies up to date | 1 to 2 days |

**SEO beyond the basics** (mostly editorial): research keywords separately in each language rather than translating them; write explainers that stay useful for months; link stories to topic pages; add Organization and breadcrumb markup. **AMP is no longer needed** for Google's Top Stories; fast ordinary pages count. Yours load no third-party code and ship resized WebP images. Guard that when ads arrive.

### Phase 2: days 30 to 90 (about 40 to 60 developer-days)

| Deliverable | Why | Effort |
|---|---|---|
| Move hosting to a VPS or managed cloud, with Cloudflare caching whole pages (section 3) | Needed for traffic spikes, background jobs, push and video | 3 to 5 days, plus migration |
| Live blog: timestamped updates on one page, with live-coverage markup | The format for breaking news, elections and crises, where speed wins | 5 to 8 days |
| Embargo: a story visible only to named staff until a set time | For agreed releases (economic data, reports); scheduling exists, embargo adds access control | 2 days |
| Photo desk: every image carries credit, source, licence and expiry | Unlicensed photos invite expensive agency claims | 3 to 4 days |
| Wire feeds (AFP, Reuters, AP or local agencies) arrive as attributed drafts | Volume and speed. Paid licences limit reuse; get quotes first | 4 to 6 days per feed |
| Translation desk: a "translate this" task, linked versions, translator credit, optional machine draft that a human must edit | Bilingual output is your edge. Today the two versions are independent with no hand-off | 4 to 6 days |
| Web push alerts (opt-in, breaking news only) | Brings readers back. Overuse and they switch it off | 3 to 4 days |
| Arabic-aware search (treats أ/إ/ا, ة/ه, ى/ي and diacritics as the same) | Today search scans every story's text: fine for hundreds of stories, slow for tens of thousands, and may miss Arabic spelling variants **[check]** | 3 to 5 days |
| Editorial dashboard: read now, traffic sources, results by section and author | Turns numbers into editorial decisions | 3 to 5 days |
| Staging site and one-click deployment from Git, tests run on every change | Test before readers see it; no hand-copying files | 2 to 3 days |

### Phase 3: months 3 to 12

- **Video.** Start on YouTube (free hosting, discovery, revenue share), embedded on the site, plus short vertical clips for TikTok and Reels. Move to an owned host (Cloudflare Stream, Bunny Stream, Mux) only when you need your own player and ads. 5 to 10 days of tech; video is mainly a staffing cost.
- **Podcasts.** For example a weekly Egypt economy podcast per language via a podcast host. 2 to 3 days.
- **Data journalism and maps.** Datawrapper or Flourish **[check right-to-left Arabic support]**, then an "Egypt economy tracker" (exchange rate, inflation, Suez Canal traffic) once data sources and licences are clear. 5 to 15 days.
- **A third language.** French, for North and West Africa, is the obvious candidate **[judgment]**. The code assumes exactly two languages in several places: 1 to 2 weeks of refactoring, plus a whole editorial team.
- **Reader accounts and memberships.** Only once a loyal audience exists; they bring password-reset email, data-protection duties and payments.
- **Apps.** First make the site installable on phones (a "progressive web app"); native apps only if data says so.

### Engagement: comments and reader accounts

**Recommendation: no open comments in year one.** They need moderation in two languages at all hours, attract spam and abuse, and in Egypt the publisher may be held responsible for what readers post **[check with lawyer]**. Instead: a curated "send us a tip or a letter" form, reader questions answered in stories, and conversation on WhatsApp, Telegram and social platforms. Revisit with memberships.

### Monetisation

| Option | What it needs | When realistic | Watch out for |
|---|---|---|---|
| Programmatic ads (Google AdSense or Ad Manager) | Steady traffic, `ads.txt`, a Google-certified consent tool for EU/UK visitors **[check]** | After months of steady traffic | Slower pages, looser security settings; Arabic ad rates tend to be low **[judgment]** |
| Direct-sold sponsorship (newsletter, section, podcast) | A salesperson, rate card, labelling policy | Months 3 to 6 | Keep advertisers out of editorial decisions |
| Sponsored or branded content | A separate team and clear "Sponsored" labels | Carefully | The fastest way to lose trust |
| Premium business briefing (Egypt economy, B2B) | A sharp franchise, reliable data | Months 6 to 12 | Best fit for your edge **[judgment]**; study existing Egypt business newsletters |
| Memberships or donations | A loyal audience; an Egyptian payment provider (such as Paymob or Fawry) | Year 2 | Stripe does not serve Egypt-based businesses, as far as I know **[check]** |
| Events and content licensing | Brand recognition | Year 2 | |
| Grants from foreign funders | A lawyer's clearance first | Only if cleared | Foreign funding of media is legally sensitive in Egypt **[check]** |

### Analytics and measurement

Measure loyalty, not just clicks: weekly returning readers, newsletter opens, search versus social traffic, original stories per day, time from event to publication, corrections issued. Build the dashboard on the site's existing daily view counts plus the analytics tool.

### Legal and regulatory: questions to put to an Egyptian media lawyer

Not legal advice: questions to settle before any launch publicity.

1. **Licensing.** Does a news website need a licence from the Supreme Council for Media Regulation under the press and media law (Law 180 of 2018) **[check]**? Cost, timing, and may we publish while it is pending? What must the editor-in-chief have (for example Journalists' Syndicate membership) **[check]**?
2. **Ownership.** What company form, capital and ownership disclosure are required? Does an English edition or hosting abroad change anything?
3. **Content law.** What do the press law, the anti-cybercrime law (Law 175 of 2018) **[check]** and the anti-terrorism law say about "false news", security, military and court reporting, and figures that differ from official statements **[check]**? What triggers a website block, and how is one challenged?
4. **Data protection.** What does the Personal Data Protection Law (Law 151 of 2020) require for newsletter emails, analytics and staff data: registration or licence, a data protection officer, consent records, transfers to email services abroad? Are its executive regulations in force **[check]**? Does the EU's GDPR apply to our European readers?
5. **Right of reply and correction.** What deadline, prominence and form does the law require?
6. **Copyright.** Under the intellectual property law (Law 82 of 2002) **[check]**, what may we take from social media, government sources and other outlets? What must wire and photo licences cover?
7. **Defamation and liability.** Criminal and civil exposure for staff and company; liability for reader and embedded posts; is media liability insurance available?
8. **People.** Staff and freelance contracts (including copyright ownership), press accreditation, safety duties.
9. **Advertising.** Labelling rules for sponsored content; tax on digital ads.
10. **Funding.** Rules on foreign investment, grants and partnerships.

---

## 3. Infrastructure scaling path

**Plain-English summary.** The site runs on a small server shared with other businesses: fine for launch and a modest audience, not for a big breaking-news spike. Here is when and how to move.

**When shared hosting stops being enough.** My rough estimate, not a measurement: the current set-up should cope with a few tens of thousands of page views a day spread evenly, but may fail above roughly 10 to 30 page requests per second, which is exactly what a widely shared breaking story produces. Confirm with a careful load test on staging, not on the shared server. Symptoms:

- Pages take more than about a second to start loading at ordinary times.
- "Resource limit reached" or 503/508 errors during spikes, typical of shared cPanel hosts **[check GoDaddy's exact limits]**.
- The admin panel freezes when several journalists save at once.
- Large photo uploads fail (PHP has only 128 MB of memory on this host).
- You need what the host cannot give: an always-on background worker, Redis, server settings.

| Stage | Rough traffic | Set-up | Rough monthly cost (prices change; get quotes) |
|---|---|---|---|
| A. Now | Up to tens of thousands of views a day, small spikes | Shared hosting, Cloudflare free in front | What you pay now |
| B. Phase 2 | Hundreds of thousands a day, big spikes | One VPS or managed Laravel host (2 to 4 CPUs, 4 to 8 GB), Redis, queue worker, Cloudflare caching pages, object storage for images | Roughly US$30 to 150 |
| C. Year 1 and beyond | Millions a day, video, live events | Managed database, two or more app servers behind a load balancer, a search service, a paid CDN and firewall plan | Roughly US$300 to 1,500 or more |

Stages B and C assume whole pages are cached at the CDN (next paragraph). Without that, capacity is far lower.

**The key change: make public pages cacheable.** Today every public page sets a session cookie, for three reasons: the footer newsletter form carries an anti-forgery token; the view counter de-duplicates per session; and staff draft previews share the public addresses. A CDN cannot safely cache a page that sets a personal cookie. Fix all three:

1. Newsletter sign-up through a token-free endpoint guarded by the existing honeypot and rate limit; double opt-in makes forged sign-ups harmless.
2. Count views with a tiny background request from the page.
3. Move staff previews to a separate, never-cached `/preview` path.

Then send `Cache-Control: public, s-maxage=60, stale-while-revalidate` on public pages and let Cloudflare serve them, with a queued job purging home, section and story pages on publish. Most reads then never reach the server.

**The rest of the path:**

- **Caching layers.** CDN for pages and images; Redis for fragments such as home blocks and Most Read; database indexes and a slow-query log.
- **Queue worker.** An always-on, supervised worker for images, newsletters, push alerts, social posting and cache purges.
- **Object storage for images.** Cloudflare R2, Amazon S3 or similar, behind the CDN. Images already go through Laravel's storage layer, so this is mostly configuration plus copying files.
- **Backups.** Nightly database dump and image sync to a different provider, 30 days kept, restore tested monthly. Targets: lose at most a day of work now, an hour from Phase 2; back online within four hours, then one.
- **Monitoring.** One-minute uptime checks alerting an on-call phone; error tracking (Sentry, Flare or similar); CPU, memory and disk alerts.
- **Security.** Staff two-step login; the admin panel behind Cloudflare Access or similar (an email code before the login page even appears); firewall rules and rate limits; SSH keys only; monthly dependency updates (`composer audit`, `npm audit`); an alert to editors on every publish, so an unexpected one is noticed in minutes. News sites are frequent targets of defacement and denial-of-service attacks **[general knowledge, no figures]**. Any new third-party script (analytics, ads, embeds) needs a deliberate entry in `app/Http/Middleware/SecurityHeaders.php`.
- **Staging and deployment.** Git, automated tests, staging, then one-click deploy (GitHub Actions, Laravel Forge or similar). The test suite exists; run it on every change.
- **Disaster recovery.** A one-page runbook: who decides, how to restore onto a fresh server, how DNS is switched in Cloudflare, how readers are told. Rehearse yearly.
- **Hosting location.** Inside or outside Egypt is partly a legal question (data protection, resilience to blocking). Ask the lawyer before Stage B.

---

## 4. Top 10 next things to build in the codebase

1. **Staff two-step login** (Filament multi-factor, app codes): one stolen password must not be able to publish fake news under your brand.
2. **Trust pages managed in the CMS** (About, Ownership, Standards, Corrections policy, Contact, Privacy, Terms): the minimum readers and Google expect.
3. **Author pages and richer bylines** (bio, photo, role, author link in the structured data): credibility is personal.
4. **Corrections, "Updated" stamps and text revision history**: visible accountability, and an internal record for disputes.
5. **Required image credit, source and licence fields**: cheap now, expensive claims later.
6. **Google News sitemap plus the Discover image tag**: the largest free distribution channel for news.
7. **Cookie-less public pages with cache headers**: unlocks CDN caching, the biggest single scaling lever.
8. **Queue, scheduler and email-service integration with double opt-in daily briefs**: builds the audience you own.
9. **Privacy-friendly analytics and an editorial dashboard**: so editors decide with data.
10. **Live blog**: the format where speed on Egyptian stories wins readers.

Next in line: topic pages, translation desk, Arabic-aware search, wire ingest, web push, embargo, third-language refactor.

---

## 5. Risks: what would make this fail

- **Not being trusted.** If readers abroad see the outlet as an arm of the state or of the owner's other interests, no technology fixes it. The name makes this harder.
- **A website without a newsroom.** Rewrites of agency copy and press releases give readers no reason to come. Google's spam policies penalise thin, copied or mass-produced content, including mass AI-written or machine-translated stories ("scaled content abuse") **[check current wording]**.
- **Legal or regulatory action.** No required licence, a story that crosses a red line, staff at risk, or the site blocked in Egypt. And the quieter risk: self-censorship that leaves nothing distinctive to read.
- **Money runs out before the habit forms.** Audiences take 12 to 24 months to build (rough). Budget for the long haul or do not start.
- **Platform dependence.** Algorithms change without warning; owned channels (newsletter, push, direct visits) are the insurance.
- **A security incident.** A hijacked staff account, defacement, or the site knocked offline during the biggest story of the year.
- **Single points of failure.** One shared server, untested backups, code on one laptop, one developer who knows the system.
- **Weak English.** An English edition that reads like a translation hurts the brand abroad more than no English at all **[judgment]**.
- **Copyright claims.** Photos taken from the internet are the most common avoidable expense for new publishers **[judgment]**.

---

## 6. Decisions only the owner can make

1. **The name.** Keep "sisi.news", or rebrand now, before money goes into the brand.
2. **Editorial independence.** Who the editor-in-chief is, what independence they have in writing, and whether ownership and funding are published.
3. **Budget and runway.** How many journalists, for how long. A credible bilingual daily operation is something like 15 to 25 people (rough).
4. **Audience and franchise.** Who comes first (Egyptians at home, Arab readers across the region, international English readers) and which one or two subjects you will own.
5. **Business model order.** Ads, sponsorship, premium briefing or membership first.
6. **Comments.** Yes or no in year one (recommendation: no).
7. **AI policy.** Whether AI may draft translations or summaries, and what you tell readers when it does.
8. **A third language.** Which one, if any, and when.
9. **Spend on infrastructure and services.** The move to a VPS and CDN, plus wires, email service and monitoring.
10. **Legal counsel.** Appoint a media lawyer now and act on the answers to the questions in section 2.
