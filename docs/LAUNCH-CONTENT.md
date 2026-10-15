# Launch content: audit sheet

> **This is launch sample copy. It is meant to be replaced by real reporting.**
> The twelve stories are evergreen explainers and features written so that the site does not launch empty. They are not news, they report no current events, and they carry no reporter's byline (they are filed under "sisi.news Desk"). An editor should unpublish or replace each one as real stories come in. Nothing here has been checked by a lawyer or by an Egyptian editor.

Files (all under `database/launch-content/`):

| File | What it is |
|---|---|
| `stories.json` | `desk` plus 12 bilingual stories, in the contract the seeder reads (`key`, `category`, `image`, `featured`, `published_hours_ago`, `tags`, `en`, `ar`). Hours-ago run from 2 to 150, so the home page shows a natural spread. Exactly one story is `featured` (the lead). |
| `images/*.jpg` | 12 landscape JPEGs, 1600 px wide, each under 410 KB, with all metadata removed (EXIF, ICC, XMP and the encoder comment). Each is used by exactly one story. |
| `images/credits.json` | One entry per picture: `title`, `author`, `license`, `license_url`, `source_url`, `credit_en`, `credit_ar`, plus `changes`, `notes` and `used_by`. |

The seeder's own validator (`LaunchContentPackage::load`) accepts the package with no problems and no warnings, and `php artisan sisi:seed-launch-content --dry-run` on this directory reports 12 stories to import (checked against a throwaway MySQL database, never the dev database).

## 0. Independent review, 12 October 2026

A second reviewer re-checked every picture's licence on its Commons file page, looked at every picture, and re-verified the facts in every story in both languages. What changed:

**Pictures**

- The three photographs taken from the owner's placeholder site were **removed from the package**. Their CDN path (`isteam/getty/`) shows they are stock photography supplied by the site builder, so "Photo: sisi.news" was a false credit and the licence cannot be shown to cover sisi.news. The lead story now uses a Commons photograph of a felucca on the Nile in Cairo; the Giza story uses a CC0 aerial photograph of the pyramids beside the city. The underpass photograph (never attached to a story) and the two earlier "alt-" stand-ins (one heavily filtered, one a wall and rubbish in the foreground) were dropped too.
- `cairo-metro-train.jpg` carried the photographer's own watermark on the ceiling vent (the earlier note "no watermark" was wrong). It is now cropped to 16:9, which removes it.
- Credit lines corrected to the names the uploaders ask for: the Suez Canal Bridge photographer is credited as **Andrew Shiva** (Commons user Godot13; the file page says reuse outside Wikipedia must credit him by that name) and the Grand Egyptian Museum photographer as **Habib Mhenni** (the spelling in his own Commons credit box, not "M'henni").
- Every local file was matched against its cited Commons original (thumbnail comparison against all twelve sources; each file matched its own source best), so no file is mislabelled.

**Facts and wording** (all in both languages)

- Nile length: the "about 7,100 km, satellite measurement of 2009" figure was removed. It rested on a Wikipedia table note; the 2009 paper's abstract gives no figure and press reports of it differ. The story now gives Britannica's 6,650 km and says published measurements differ.
- Nile delta: the story said Britannica puts the delta at about 22,000 km2. Britannica's Egypt article gives about 25,000 km2 and Wikipedia gives 22,000, so the area was dropped; the text now says the delta begins at Cairo and is about 160 km long from north to south (Wikipedia, Nile Delta).
- Suez Canal Bridge: "enough for every type of vessel to pass" removed (stronger than any source; Wikipedia gives 68 m as the maximum ship height). The "same water level on both sides" line became "close to the same level".
- UNCTAD: the current UNCTAD wording is "around 80 per cent", not "over 80 per cent".
- Abu Simbel: UNESCO says donations from some 50 countries covered **half** of the US$80 million cost, not all of it.
- Senate: Article 248 was paraphrased with items it does not contain ("national unity", "means"); it now follows the Constitute English text and the official Arabic opening. Article 253 now names the prime minister, deputies and ministers as the text does.
- Parliament seat: "Parliament's headquarters stand in the New Administrative Capital" became "A new parliament complex stands in the New Administrative Capital", because the sources disagree on whether all sittings have moved.
- Grand Egyptian Museum: "completed in 2023" removed (only one source); the chronology now keeps 1992, 2002, 2005, the trial run of October 2024 and the opening of 1 November 2025.
- Cairo Metro: the "3.5 to more than 4 million passengers a day" range was never read at its source (see 2.6) and was replaced by "millions every day".
- Arab League: Article 2's list now matches the Pact (nationality, passports and visas, execution of judgments, extradition).
- Bibliotheca Alexandrina: the claim that the plaza stands where the ancient library stood is now attributed to the library itself ("according to the library"), and "carved" granite became "displays letters".
- Nile basin: Nile Basin Initiative members are now "listed by the Initiative"; the basin area is flagged as varying with how the basin is drawn.
- Arabic polish: Cairo story ("الخبر" to "الموضوع", "مستوطنة الفسطاط" to "مدينة الفسطاط"), "الدرج العظيم" for the Grand Staircase (the museum's own term), the Charter's own wording for General Assembly voting ("الحاضرين والمشتركين في التصويت"), a clearer Abu Simbel opening line, a new Parliament headline ("غرفتان" was wrong for a body whose upper house only advises), and the UNESCO site named by description rather than by an unverified Arabic title.

**Not resolved** (needs the owner): see section 4, points 1 to 4.

## 1. Pictures

Licence key: all Commons files are licensed CC BY, CC BY-SA or CC0. Re-checked on 12 October 2026 against each Commons file page (licence template, author, "own work" or Flickr review status): no NonCommercial, NoDerivatives, fair-use or unclear licences, and no deletion or copyright-violation tags. Victoria Nile and the Giza aerial carry a passed Flickr licence review. CC BY and CC BY-SA require the credit line to be shown with the picture and, for edited copies, a note that the picture was changed. The `hero_caption` of every story ends with the credit line from `credits.json`, and "(cropped)" is added where the picture was cropped. Resizing and re-encoding were applied to every file.

| File | Subject | Used by story | Author | Licence | Source |
|---|---|---|---|---|---|
| `nile-felucca-cairo.jpg` | A felucca on the Nile in central Cairo, Cairo Tower behind (cropped) | `nile-through-cairo` (lead) | Mo7amedsalim | CC BY-SA 3.0 (the page also offers GFDL) | https://commons.wikimedia.org/wiki/File:Nil_in_Cairo.JPG |
| `suez-canal-bridge.jpg` | Egyptian-Japanese Friendship Bridge, approach viaduct | `suez-canal-193-kilometre-shortcut` | Andrew Shiva (Commons: Godot13) | CC BY-SA 4.0 | https://commons.wikimedia.org/wiki/File:Suez_Canal_Bridge_(2008)_08.jpg |
| `egypt-parliament.jpg` | Parliament building, New Administrative Capital | `egypt-parliament-two-chambers` | Abdelrhman 1990 | CC BY 4.0 | https://commons.wikimedia.org/wiki/File:The_Egyptian_Parliament_building.jpg |
| `grand-egyptian-museum.jpg` | Interior of the Grand Egyptian Museum, a pyramid-shaped stone beside the staircase | `grand-egyptian-museum-beside-the-pyramids` | Habib Mhenni (Commons: Dyolf77) | CC BY-SA 4.0 | https://commons.wikimedia.org/wiki/File:GEM,_December_22nd,_2025,_by_Dyolf77,_ZVE07639.jpg |
| `un-headquarters.jpg` | UN Headquarters on the East River, New York | `united-nations-six-organs-193-members` | Jakub Hałun | CC BY 4.0 | https://commons.wikimedia.org/wiki/File:Shore_of_the_East_River_with_Headquarters_of_the_United_Nations,_New_York_City,_20231005_1131_2226.jpg |
| `cairo-metro-train.jpg` | Inside a Cairo Metro train car (cropped, watermark removed by the crop) | `cairo-metro-africas-first-full-metro` | محمد عيد صالح | CC BY-SA 4.0 | https://commons.wikimedia.org/wiki/File:%D9%85%D8%AA%D8%B1%D9%88_%D8%A3%D9%86%D9%81%D8%A7%D9%82_%D8%A7%D9%84%D9%82%D8%A7%D9%87%D8%B1%D8%A9.jpg |
| `arab-league-cairo.jpg` | Arab League headquarters, Cairo (cropped) | `arab-league-pact-cairo-1945` | R Prazeres | CC BY-SA 4.0 | https://commons.wikimedia.org/wiki/File:Arab_League_building_Cairo_DSCF1978.jpg |
| `giza-pyramids-aerial.jpg` | Aerial view of the Giza pyramids at the edge of Greater Cairo (cropped) | `giza-pyramids-edge-of-cairo` | lumoplank | CC0 | https://commons.wikimedia.org/wiki/File:Giza,_Dahshur_and_Saqqara_-_Giza8254.jpg |
| `victoria-nile.jpg` | The Victoria Nile north of Jinja, Uganda (cropped) | `nile-basin-eleven-countries-one-river` | Bernard DUPONT | CC BY-SA 2.0 | https://commons.wikimedia.org/wiki/File:Victoria_Nile_out_of_Lake_Victoria_(18319022776).jpg |
| `constitutional-court.jpg` | Supreme Constitutional Court, Maadi (cropped) | `supreme-constitutional-court-built-like-a-temple` | Ahmad Badr | CC BY-SA 3.0 | https://commons.wikimedia.org/wiki/File:Supreme_Constitutional_Court_of_Egypt.JPG |
| `abu-simbel-lake.jpg` | Abu Simbel temples from Lake Nasser (cropped) | `abu-simbel-the-temples-that-moved` | Olaf Tausch | CC BY 3.0 | https://commons.wikimedia.org/wiki/File:Abu_Simbel_Tempel_07.jpg |
| `bibliotheca-alexandrina.jpg` | Reading hall, Bibliotheca Alexandrina | `bibliotheca-alexandrina-reading-room-tilted-disc` | Ahmedsalahemam | CC BY-SA 4.0 | https://commons.wikimedia.org/wiki/File:Alexandria_library_(9).jpg |

Notes on individual pictures (also in `credits.json` under `notes`):

- Every picture was viewed in full: sharp, no watermark, matches its story, nothing sensitive, no identifiable private person as the subject. The Grand Egyptian Museum and Bibliotheca Alexandrina pictures show only distant, unidentifiable visitors.
- `nile-felucca-cairo.jpg` was taken in 2008 with a compact camera and is a little soft and hazy. A hotel sign is legible on the tower at the left; it is incidental to the skyline.
- `suez-canal-bridge.jpg` shows the bridge's long approach viaduct, not the canal water; the caption says only that the bridge crosses the canal at El Qantara.
- `cairo-metro-train.jpg`: the Commons title and category say Cairo Metro, but the carriage design was not independently matched to a particular line, so the caption only says "a Cairo Metro train car".
- `giza-pyramids-aerial.jpg` is hazy by nature (aerial view over Cairo's air). Its Commons description reads "Aerial view of the Giza pyramid complex on the edge of the Cairo metropolitan area, with three pyramids visible above the urban sprawl".
- **Buildings and Egyptian law.** Wikimedia Commons treats Egypt as having no usable freedom of panorama (Commons:Copyright rules by territory/Egypt), so a photograph whose subject is a modern, copyrighted building can in principle infringe the architect's copyright even though the photographer's licence is valid. Commons itself puts a "no freedom of panorama" notice on its Grand Egyptian Museum category. Affected here: `grand-egyptian-museum.jpg` (explicitly flagged by Commons), `egypt-parliament.jpg`, `constitutional-court.jpg` (2000), `arab-league-cairo.jpg` (1955) and `bibliotheca-alexandrina.jpg` (2002). Such pictures are routine in news, the risk of a claim over an explainer is low, and no replacement shows these buildings without showing the building, but it is a legal question the site owner should decide. The Suez Canal Bridge is an engineering structure and the UN Headquarters is in the United States, where photographs of buildings are allowed.
- Candidates looked at and rejected: US Navy and Army photographs of warships in the canal (military subject for a civilian trade story), a container-ship photograph with a visible shipping-line logo, satellite images of the canal (not photographs), a cruise-ship photograph of a convoy in the canal (passengers recognisable, and the Commons author field is a web-archive link rather than a name), a Grand Egyptian Museum construction-site photograph covered in contractors' logos, GEM atrium photographs filled with identifiable visitors, a second Supreme Constitutional Court photograph cluttered with traffic, and Nile pictures carrying a hotel name, a soft-drink logo on a sail, or heavy filtering.

## 2. Stories and their verified facts

Article numbers for the Constitution refer to the English translation of the 2014 Constitution as amended in 2019 on Constitute. Figures are rounded only as stated. "Opened" means the page itself was read during the independent review on 12 October 2026; "search excerpt" means the page returned HTTP 403 to the fetch tool and the fact was confirmed from a search-result excerpt only. Wikipedia is a secondary source; rows that rest on Wikipedia alone are marked **(W)** so an editor can look for a stronger source first.

### 2.1 `nile-through-cairo` (Egypt, lead, featured, 2 h ago)

| Fact in the story | Source |
|---|---|
| The Nile flows north and empties into the Mediterranean, forming a delta; the delta begins at Cairo and is about 160 km long from north to south | Wikipedia, Nile https://en.wikipedia.org/wiki/Nile and Nile Delta https://en.wikipedia.org/wiki/Nile_Delta (both opened) **(W)** |
| Length about 6,650 km (Britannica); published measurements differ | Britannica, Nile River https://www.britannica.com/place/Nile-River (403; search excerpt). Wikipedia's list of river systems by length shows 6,650 km (Britannica) with 7,088 km in brackets (Liu et al. 2009); that 2009 figure was dropped because the paper's abstract (Zenodo) gives no number and press reports of it differ |
| Cairo founded 969 by the Fatimids; seat of the Fatimid caliphate in 973; al-Qahira = "the Vanquisher" or "the Conqueror" | Wikipedia, Cairo https://en.wikipedia.org/wiki/Cairo (opened) **(W)** |
| Fustat founded after the Muslim conquest, 641-642 | Wikipedia, Cairo (opened) **(W)** |
| Gezira is an island in the Nile in central Cairo; Zamalek is its northern third | Wikipedia, Gezira Island https://en.wikipedia.org/wiki/Gezira_Island (opened) **(W)** |
| Feluccas are lateen-rigged sailing boats still in use on the Nile | Oxford Reference, felucca https://oxfordreference.com/view/10.1093/oi/authority.20110803095814480 (search excerpt); Britannica, felucca https://www.britannica.com/technology/felucca |
| About 95% of Egyptians live within roughly 20 km of the Nile and delta; vast areas elsewhere are sparsely populated or uninhabited | CIA World Factbook, Egypt (current wording: "approximately 95% of the population lives within 20 km of the Nile River and its delta"; the 2023 archive says about 5% of the land) https://www.cia.gov/the-world-factbook/about/archives/2023/countries/egypt (search excerpt); INED https://www.ined.fr/fr/tout-savoir-population/memos-demo/focus/egypte/ |
| Greater Cairo has more than 20 million people; one of the largest urban areas in Africa and the Arab world | Wikipedia, Cairo (over 22 million; rounded down on purpose) **(W)** |

### 2.2 `suez-canal-193-kilometre-shortcut` (Business, 9 h ago)

| Fact | Source |
|---|---|
| Opened for navigation November 1869 (17 November); length 193.30 km | Suez Canal Authority, FAQ https://www.suezcanal.gov.eg/English/Pages/FAQ.aspx and Canal Characteristics https://www.suezcanal.gov.eg/English/About/SuezCanal/Pages/CanalCharacteristics.aspx (both opened); the 17 November day from the SCA's 150th-anniversary news https://www.suezcanal.gov.eg/English/MediaCenter/News/Pages/sca_17-11-2019.aspx and Wikipedia, Suez Canal https://en.wikipedia.org/wiki/Suez_Canal |
| Runs from Port Said (north) to Suez (south) | Wikipedia, Suez Canal **(W)** |
| Sea-level waterway with no locks; ships avoid the voyage around the Cape of Good Hope | Britannica, Suez Canal https://www.britannica.com/topic/Suez-Canal (search excerpt); Wikipedia, Suez Canal ("Locks: None") |
| Operated by the Suez Canal Authority, established 26 July 1956 | Wikipedia, Suez Canal Authority https://en.wikipedia.org/wiki/Suez_Canal_Authority (opened: "established by the nationalization act signed on 26 July 1956"); the SCA's own FAQ states no date **(W)** |
| Opened at about 8 m depth; maximum water depth 24 m in the 2015 column | SCA, Canal Characteristics (opened: max water depth 8 m in 1869, 24 m in 2015) |
| Friendship Bridge: cable-stayed, at El Qantara close to Ismailia, 70 m clearance, 154 m pylons shaped like pharaonic obelisks, Japanese grant of JPY 13.5 bn for about 60% of JPY 22.5 bn, grant project ended October 2001 | JICA https://www.jica.go.jp/english/overseas/egypt/activities/activity15.html (opened); Wikipedia, Suez Canal Bridge https://en.wikipedia.org/wiki/Suez_Canal_Bridge (opened: opened October 2001) |
| Around 80% of the volume of world goods trade goes by sea | UNCTAD, Review of Maritime Transport https://unctad.org/topic/transport-and-trade-logistics/review-of-maritime-transport (403; the current wording is "around 80%"; some earlier editions say "over 80%"; search excerpts) |

Left out on purpose: the canal's share of world trade (sources give different figures and years, and the figure moved sharply in 2024), annual transits and revenue, and whether the bridge's clearance suits every vessel (Wikipedia gives 68 m as the maximum ship height).

### 2.3 `egypt-parliament-two-chambers` (Politics, 14 h ago)

| Fact | Source |
|---|---|
| House of Representatives holds legislative authority, approves general policy, development plan and budget, oversees the executive (Art. 101); five calendar years from its first session (Art. 106); at least 450 members, at least a quarter of seats for women (Art. 102) | Constitute, Egypt 2019 https://www.constituteproject.org/constitution/Egypt_2019 (opened; Arabic wording of Arts. 101, 102 and 106 checked at https://www.constituteproject.org/constitution/Egypt_2019?lang=ar) |
| Senate: purpose (Art. 248, "consolidate democracy, support national unity, social peace, the basic values of society, supreme values, rights, freedoms and public duties, and deepen and expand the democratic system"); opinion sought on constitutional amendments, the development plan, treaties on rights of sovereignty (Art. 249); at least 180 members, two-thirds elected and the rest appointed by the President (Art. 250); no dual membership (Art. 252); the prime minister, his deputies, ministers and other members of the government are not accountable to the Senate (Art. 253) | Constitute, Egypt 2019 (opened, English); State Information Service, Senate page https://sis.gov.eg/ar/مصر/نظام-الحكم/السلطة-التشريعية/مجلس-الشيوخ/ (opened: Arabic opening of Art. 248, Arts. 249 (start), 250, 252, 253) |
| Senate restored by amendments approved 20-22 April 2019; the Shura Council had been abolished in 2014 | Wikipedia, 2019 Egyptian constitutional referendum https://en.wikipedia.org/wiki/2019_Egyptian_constitutional_referendum (opened) **(W)** |
| Parliamentary life dates to 1866 | Wikipedia, Parliament of Egypt https://en.wikipedia.org/wiki/Parliament_of_Egypt (opened: "as early as 1866") **(W)** |
| New parliament complex in the New Administrative Capital, about 45 km east of Cairo | Wikipedia, New Administrative Capital https://en.wikipedia.org/wiki/New_Administrative_Capital (opened); the picture's Commons description **(W)** |

Left out on purpose: seat counts and the electoral system in force (sources conflict: 596 seats, 300 senators, list and individual seats, up to 5% presidential appointees to the House), election results, officeholders, and whether all sittings have moved to the new complex.

### 2.4 `grand-egyptian-museum-beside-the-pyramids` (Culture, 22 h ago)

| Fact | Source |
|---|---|
| About 2 km from the Giza Pyramid complex; site of 500,000 m2; official opening 1 November 2025; "the largest museum in the world for a single civilization" (a description, not an audited ranking) | Wikipedia, Grand Egyptian Museum https://en.wikipedia.org/wiki/Grand_Egyptian_Museum (opened) **(W)**; TBS News https://www.tbsnews.net/world/egypt-set-open-grand-museum-lavish-ceremony-1274541 (opened: opening date 1 November 2025, 500,000 m2, "the world's largest archaeological museum", about 100,000 artefacts spanning seven millennia) |
| Grand staircase lined with statues, leading to a level with a framed view of the pyramids; staircase about 50 m high with more than 60 artefacts | Wikipedia, Grand Egyptian Museum **(W)**; TBS News (statues, upper-level view) |
| 11-metre Ramesses II statue moved from Ramses Square in 2006 and placed in the Grand Hall in 2018 | Wikipedia, Grand Egyptian Museum **(W)**; TBS News (11 m, moved in 2006) |
| Tutankhamun's tomb discovered in 1922 by Howard Carter | TBS News |
| Idea proposed 1992; foundation stone 2002; construction began 2005; trial run of part of the galleries from October 2024; official opening 1 November 2025 | TBS News (proposed 1992, construction began 2005); Wikipedia, Grand Egyptian Museum (foundation stone 2002) **(W)**; AP and Al Jazeera, trial run from 16 October 2024 https://www.aljazeera.com/gallery/2024/10/16/grand-egyptian-museum-opens-for-trial-run (search results); hence "according to published chronologies" in the text |

Left out on purpose: artefact counts (sources give 5,000, 5,398, 5,500 and 5,900 for Tutankhamun and 24,000, 50,000 or 100,000 for the whole museum), the cost and the 2023 "completion" date (only Wikipedia gives it).

### 2.5 `united-nations-six-organs-193-members` (World, 31 h ago)

| Fact | Source |
|---|---|
| UN officially began 24 October 1945; representatives of 50 countries met in San Francisco | UN, History of the UN https://www.un.org/en/about-us/history-of-the-un |
| Egypt an original Member from 24 October 1945 | UN, Egypt https://www.un.org/en/about-us/member-states/egypt |
| 193 Member States | UN, Main bodies https://www.un.org/en/about-us/main-bodies (opened: "All 193 Member States"); UN, Member States https://www.un.org/en/about-us/member-states |
| Six principal organs (Art. 7); General Assembly consists of all Members (Art. 9), one vote each and two-thirds majority of members present and voting on important questions (Art. 18) | UN Charter, chapters III and IV https://www.un.org/en/about-us/un-charter/chapter-3 and https://www.un.org/en/about-us/un-charter/chapter-4 |
| Security Council: 15 members; permanent members China, France, Russian Federation, United Kingdom, United States; ten elected by the General Assembly for two years, five a year | UN Dag Hammarskjold Library https://research.un.org/en/unmembers/scmembers ; UN, Main bodies (opened: 15 members, 5 permanent and 10 non-permanent, one vote each) |
| ECOSOC 54 members; Trusteeship Council suspended operation 1 November 1994; ICJ seat at the Peace Palace in The Hague, the only organ not in New York; Secretary-General five-year renewable term | UN, Main bodies (opened) |
| Boutros Boutros-Ghali of Egypt, sixth Secretary-General, January 1992 to December 1996, born in Cairo, first African in the post | UN press-release archive (appointment by the General Assembly as sixth Secretary-General, term from 1 January 1992) via search results; UN, Boutros Boutros-Ghali https://www.un.org/sg/en/content/boutros-boutros-ghali (returned an empty page to the fetch tool); "first African" follows from the list of Secretaries-General, none of the five before him being African |

### 2.6 `cairo-metro-africas-first-full-metro` (Business, 40 h ago)

| Fact | Source |
|---|---|
| Line 1 opened in 1987 from Helwan to Ramses Square (29 km); construction began 1982 after the French government agreed the loan; completed to El Marg in 1989; "the first of the three full-fledged metro systems in Africa" | Wikipedia, Cairo Metro https://en.wikipedia.org/wiki/Cairo_Metro (opened) **(W)** |
| Line 2 first opened 1996; Line 3 phase 1 (Attaba to Abbassia) opened 21 February 2012 | Wikipedia, Cairo Metro **(W)** (its Line 2 text and table disagree on later dates; only the first opening is used) |
| Owned by the National Authority for Tunnels; Lines 1 and 2 run by the Egyptian Co. for Metro Management and Operation | Wikipedia, Cairo Metro **(W)** |
| Cars reserved for women on every train | Wikipedia, Cairo Metro **(W)** (middle two cars since 1989) |
| Greater Cairo more than 20 million | Wikipedia, Cairo **(W)** |

The weakest story for sourcing: almost all of it rests on Wikipedia. Left out on purpose: the women-only cars' layout and start year (sources disagree: "first and fifth", "middle two", 1989, 2007), any daily ridership figure (the earlier "3.5 to more than 4 million" range was never read at its sources; Wikipedia's infobox lists 5 million a day and 1,460 million trips in 2023, which is itself about 4 million a day), Line 3's later phases and Line 4, which is under construction.

### 2.7 `arab-league-pact-cairo-1945` (Regional affairs, 55 h ago)

| Fact | Source |
|---|---|
| Pact dated 22 March 1945 = 8 Rabi' al-Thani 1364; Art. 1 membership; Art. 2 purpose and areas of cooperation (economic and financial affairs; communications; cultural affairs; nationality, passports, visas, execution of judgments and extradition; social affairs; health); Art. 3 Council, one vote per state; Art. 4 special committees; Art. 7 unanimous decisions bind all, majority decisions bind those that accept them; Art. 10 permanent seat in Cairo; Art. 11 two ordinary sessions a year, March and October; Art. 12 Secretary-General appointed by two-thirds | Pact of the League of Arab States, English text, Avalon Project, Yale https://avalon.law.yale.edu/20th_century/arableag.asp (opened) |
| Formed in Cairo; Egypt among the founders; 22 members | Wikipedia, Arab League https://en.wikipedia.org/wiki/Arab_League **(W)** |

Left out on purpose: the number of founding members (sources say six or seven; Yemen signed on 5 May 1945), the 1979-1990 move of the headquarters to Tunis, summits, the current Secretary-General, and any statement about members' status.

### 2.8 `giza-pyramids-edge-of-cairo` (Egypt, 68 h ago)

| Fact | Source |
|---|---|
| Edge of the Western Desert, about 9 km west of the Nile and about 13 km south-west of central Cairo, in the city of Giza; Great Pyramid, Khafre, Menkaure; the only Ancient Wonder still in existence; built about 2580-2560 BCE for Khufu (roughly 4,500 years ago) | Wikipedia, Giza pyramid complex https://en.wikipedia.org/wiki/Giza_pyramid_complex (opened) **(W)** |
| Original height about 147 m (481 ft) | Britannica, Great Pyramid of Giza https://www.britannica.com/place/Great-Pyramid-of-Giza (search excerpt) |
| Part of the World Heritage property "Memphis and its Necropolis - the Pyramid Fields from Giza to Dahshur", inscribed 1979 | Wikipedia, Giza pyramid complex (opened); UNESCO https://whc.unesco.org/en/list/86/ (403; name from UNESCO search results). The Arabic text describes the property instead of quoting an Arabic title, which could not be confirmed. |
| Grand Egyptian Museum about 2 km away; Greater Cairo more than 20 million | Wikipedia, Grand Egyptian Museum and Cairo **(W)** |
| The picture: aerial view of the pyramid complex on the edge of the Cairo metropolitan area | Commons description of the file |

### 2.9 `nile-basin-eleven-countries-one-river` (Regional affairs, 84 h ago)

| Fact | Source |
|---|---|
| White Nile begins near Lake Victoria, flows through Uganda and South Sudan; Blue Nile begins near Lake Tana in Ethiopia and enters Sudan from the south-east; they meet at Khartoum | Wikipedia, Nile https://en.wikipedia.org/wiki/Nile (opened) **(W)** |
| Eleven countries lie wholly or partly in the basin (Burundi, DR Congo, Egypt, Eritrea, Ethiopia, Kenya, Rwanda, South Sudan, Sudan, Tanzania, Uganda) | Wikipedia, Nile (opened: "Eleven countries are wholly or partially within the basin"); Britannica's list of basin countries (search excerpt) |
| Victoria Nile is the stretch of the White Nile in Uganda starting at the Lake Victoria outlet at Jinja | Wikipedia, Victoria Nile https://en.wikipedia.org/wiki/Victoria_Nile ; picture description on Commons ("North of Jinja, Uganda") |
| Nile Basin Initiative: "an intergovernmental partnership of 10 Nile Basin countries", a forum for consultation and coordination, established 22 February 1999; lists 10 members (Burundi, DR Congo, Egypt, Ethiopia, Kenya, Rwanda, South Sudan, Sudan, Tanzania, Uganda), Eritrea an observer; secretariat in Entebbe | Nile Basin Initiative https://nilebasin.org/ (opened) |
| Basin area about 3.35 million km2 (Britannica: 3,349,000 km2) | Britannica, Nile River (search excerpt). Wikipedia gives lower figures (2.9 and 3.1 million km2), so the text says estimates vary |
| Delta begins at Cairo, about 160 km from north to south | Wikipedia, Nile Delta https://en.wikipedia.org/wiki/Nile_Delta (opened) **(W)** |

Left out on purpose, deliberately: water sharing, treaties, dams, members' suspensions or disputes, and any share-of-flow figure (sources disagree between about 60% and 80% for the Blue Nile). The story is geography and an institution's own description of itself. The delta's area was dropped because Britannica (about 25,000 km2) and Wikipedia (22,000 km2) differ.

### 2.10 `supreme-constitutional-court-built-like-a-temple` (Politics, 101 h ago)

| Fact | Source |
|---|---|
| Independent judicial body based in Cairo (Art. 191); exclusively competent on constitutionality of laws and regulations, interprets legislative texts, adjudicates disputes between judicial bodies and over two contradictory final rulings (Art. 192); rulings published in the Official Gazette and binding on everyone and all state authorities (Art. 195) | Constitute, Egypt 2019 (opened, English) |
| Set up by Law No. 48 of 1979; name from the 1971 Constitution; earlier Supreme Court created by Law No. 81 of 1969 | Wikipedia, Supreme Constitutional Court of Egypt https://en.wikipedia.org/wiki/Supreme_Constitutional_Court_of_Egypt (opened) **(W)** |
| On the Nile Corniche in Maadi; ancient Egyptian elements with contemporary design; said to be inspired by the Temple of Luxor | Wikipedia, same article (opened: "The building is inspired by the Temple of Luxor") **(W)**; Archnet https://archnet.org/sites/5063 (building description) |

Left out on purpose: the architect (the Commons description and Wikipedia name Ahmed Mito, but no independent source), the year of completion (Commons says 2000, Arabcont says 2001), and the court's current membership.

### 2.11 `abu-simbel-the-temples-that-moved` (World, 124 h ago)

| Fact | Source |
|---|---|
| Carved in the 13th century BCE (begun about 1264 BCE) under Ramesses II; Aswan Governorate near the Sudanese border; four colossal seated statues on the Great Temple; small temple for Hathor, personified by Nefertari | Wikipedia, Abu Simbel temples https://en.wikipedia.org/wiki/Abu_Simbel_temples (opened) **(W)** |
| Lake Nasser, the reservoir behind the Aswan High Dam, would have submerged the site | Wikipedia, Abu Simbel temples (opened); JSTOR Daily https://daily.jstor.org/an-epic-face-lift-moving-abu-simbel-out-of-the-nile ; UNESCO https://whc.unesco.org/en/activities/1296 (opened) |
| Campaign launched 8 March 1960; some 30 countries set up national committees; some 50 countries donated, covering half of the US$80 million cost; 22 monuments and complexes saved | UNESCO, Nubia campaign https://whc.unesco.org/en/activities/1296 (opened) |
| Cut into more than a thousand blocks, reassembled in an artificial hill; inaugurated 22 September 1968 | UNESCO, Nubia campaign (opened: "more than 1,000 blocks", inaugurated 22 September 1968, "a specially built artificial cliff"); Wikipedia gives 1,036 (or 807 plus 235) blocks **(W)** |
| Inscribed on the World Heritage List in 1979 as "Nubian Monuments from Abu Simbel to Philae" | Wikipedia, Abu Simbel temples (opened: reference no. 88) **(W)**; UNESCO list 88 (403) |
| The campaign spurred the World Heritage Convention (adopted 1972) | UNESCO, Nubia campaign (opened: "spurred the development of the World Heritage Convention"); the 1972 adoption is general knowledge, UNESCO news https://whc.unesco.org/en/news/2677 |

Left out on purpose: how far and how high the temples moved (UNESCO says 64 m and 180 m, Wikipedia says 65 m and 208 m) and the temples' alignment with the sun.

### 2.12 `bibliotheca-alexandrina-reading-room-tilted-disc` (Culture, 150 h ago)

| Fact | Source |
|---|---|
| Officially inaugurated 16 October 2002 | Bibliotheca Alexandrina brochure, third edition http://www.bibalex.org/attachments_ar/Publications/Files/EnglishBrochure.pdf (opened and text-searched: "officially inaugurated on 16 October 2002") |
| Per the library's own account the plaza stands where the old library and the royal palace of the Ptolemies stood; slanted disc; gray granite wall displaying letters from the alphabets of some 120 languages; Snohetta Hamza Consortium (Norwegian and Egyptian architects) | Bibliotheca Alexandrina, "A Landmark Building" https://www.bibalex.org/Attachments/Publications/Files/LandMark_English.pdf (opened and text-searched) |
| Reading area cascades over seven levels, accommodates 2,000 readers; designed for up to eight million volumes, 500,000 on open shelves and 7.5 million in storage | Bibliotheca Alexandrina brochure (opened and text-searched) |
| Complex includes a planetarium, three museums (antiquities, manuscripts, history of science) and specialised libraries (children, visually impaired, rare books) | Bibliotheca Alexandrina, "A Landmark Building" (opened) |
| Ancient Mouseion launched in 288 BCE by Ptolemy I; Alexandria's site chosen by Alexander in 331 BCE; the Pharos one of the Seven Wonders | Bibliotheca Alexandrina brochure (opened: "Launched in 288 BC by Ptolemy I (Soter)"; "in 331 BCE, Alexander the Great ... selected the site"); the text attributes the 288 BCE date to the library |

Left out on purpose: the building's "160 m" (the brochure says 160 m diameter for the foundation wall; the essay says 160 m circumference), the "eleven levels" figure (the main building has 11 levels, a different count from the seven-level reading area) and the 1988/1989 competition date (sources differ).

## 3. Editorial rules this package follows

- No event, quote, statistic, named private individual or company is invented. The only people named are historical or institutional: Ramesses II, Khufu, Ptolemy I, Alexander the Great, Howard Carter and Boutros Boutros-Ghali (UN's own record). The only organisations named are public bodies, the UN system, the Suez Canal Authority, Egypt's institutions, the Arab League, the Nile Basin Initiative, UNESCO, UNCTAD, JICA, the Encyclopaedia Britannica and the architecture consortium named by the library itself.
- Politics and regional-affairs pieces are institutional explainers of how a body is built, quoting the founding text. They do not evaluate any government, party or person.
- No relative-time words ("today", "now", "recently", "currently"); every date is absolute. Arabic was written natively, not translated, and checked for Latin words in body text, Latin punctuation and hidden bidirectional control characters (none).
- Bodies are plain `p` and `h2` elements only: no images, no inline styles, no blockquotes.

## 4. Open points for the owner and editor

1. **Buildings and Egyptian copyright (decision for the owner).** Five pictures show modern Egyptian buildings (section 1). The photographers' licences are valid; the open question is the architects' copyright in a country that Commons treats as having no freedom of panorama. Commons itself flags the Grand Egyptian Museum. The risk is low for editorial use, but if the owner wants none, swap those heroes for pictures that do not show a modern building; a swap is one edit to `image` in `stories.json` plus the caption's credit line.
2. **Licence record for the owner's placeholder photos.** They are not shipped. If the owner wants them, the licence terms from the site builder must be obtained first, and the credit must name the real photographer or the builder, not "sisi.news".
3. **Arabic editor.** The Arabic was written and proofread by a model, then re-read by a second model reviewer. Please have the Arabic desk read the twelve Arabic bodies once before launch, especially the constitutional and legal wording. The Arabic of Articles 101, 102 and 106 was matched to the official text, and the Arabic of Articles 248, 250, 252 and 253 was checked against the State Information Service page; the Arabic of Articles 191, 192, 195 and most of 248 and 249 follows the English text and was not matched word for word to the official Arabic.
4. **Fetch limits.** Britannica, UNESCO World Heritage list pages, UNCTAD and the GEM site returned HTTP 403 to the fetch tool, so Britannica's length, basin area and Great Pyramid height, UNCTAD's "around 80 per cent", the UNESCO property names and the 1979 inscription years were confirmed from search excerpts and from Wikipedia, not from the pages. They are marked above. They are general, widely reported facts, but an editor should open the links once.
5. Most facts about the Cairo Metro, the Arab League's membership, the Senate's history and the Grand Egyptian Museum's chronology rest on Wikipedia and news reports (marked **(W)**).
6. `credits.json` adds `changes`, `notes` and `used_by` to the fields in the contract; the importer ignores them. The seeder should show the `credit_en` and `credit_ar` lines (the hero captions already end with them), which is what the CC BY and CC BY-SA licences require.
7. The Arab League "22 members" and UN "193 Member States" counts are stable now but would need updating if membership changed. The "largest museum for a single civilisation" wording for the GEM is a description repeated by news outlets, not an audited ranking, and the text says "described as".
8. The seeder gets "sisi.news Desk" as the byline. The package names no individual author, so launch copy appears as desk copy, which is the honest label for sample text. Whoever publishes it should decide whether the site needs a visible note on the stories that they are explainers.
