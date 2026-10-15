<?php

namespace Database\Seeders\Support;

use App\Enums\Locale;

/**
 * Placeholder newsroom content for DemoContentSeeder: eighteen invented, politically
 * neutral stories (three per section) in Modern Standard Arabic and English. Every body
 * ends with a line saying it is fictional, so nobody mistakes it for reporting.
 *
 * Story fields:
 *   key       stable id; also the hero image file name
 *   category  English slug of the section
 *   author    staff key (see DemoContentSeeder::STAFF)
 *   tags      keys of self::TAGS
 *   hours     publication (or last activity) this many hours ago; negative = scheduled for later
 *   pop       relative popularity, drives the seeded daily view counts
 *   hero      false = no picture
 *   featured  true = flagged featured
 *   breaking  'active' (no expiry) or 'expired'
 *   ar / en   one language version; leave `en` out for a story without an English version
 *               state   draft | in_review | changes_requested | published | scheduled | archived
 *               note    reviewer's note, for changes_requested
 */
final class DemoStories
{
    /** key => [English name, Arabic name] */
    public const TAGS = [
        'economy' => ['Economy', 'اقتصاد'],
        'trade' => ['Trade', 'تجارة'],
        'shipping' => ['Shipping', 'الملاحة'],
        'energy' => ['Energy', 'الطاقة'],
        'technology' => ['Technology', 'التكنولوجيا'],
        'education' => ['Education', 'التعليم'],
        'health' => ['Health', 'الصحة'],
        'environment' => ['Environment', 'البيئة'],
        'weather' => ['Weather', 'الطقس'],
        'cities' => ['Cities', 'المدن'],
        'parliament' => ['Parliament', 'البرلمان'],
        'travel' => ['Travel', 'السفر'],
        'cinema' => ['Cinema', 'السينما'],
        'museums' => ['Museums', 'المتاحف'],
        'books' => ['Books', 'الكتب'],
    ];

    private const DISCLAIMER = [
        'ar' => 'ملاحظة: هذا خبر تجريبي من محتوى وهمي أُعدّ لاختبار موقع sisi.news، ولا يصف أحداثاً أو أشخاصاً حقيقيين.',
        'en' => 'Note: this is a fictional demo story created to test sisi.news. It does not describe real events or people.',
    ];

    /** @return list<array<string, mixed>> */
    public static function all(): array
    {
        return [
            ...self::egypt(),
            ...self::politics(),
            ...self::business(),
            ...self::regionalAffairs(),
            ...self::world(),
            ...self::culture(),
        ];
    }

    /**
     * Article body as the rich-text editor would store it: lead, text, subheading,
     * text, pull quote, text, then the fictional-content note.
     *
     * @param  array<string, mixed>  $text  one language entry of a story
     */
    public static function bodyHtml(array $text, Locale $locale): string
    {
        $paragraphs = $text['paras'];
        $quote = '<blockquote><p>'.e($text['quote']).'</p><p>— '.e($text['by']).'</p></blockquote>';

        return implode('', [
            self::paragraph($text['intro']),
            self::paragraph($paragraphs[0]),
            '<h2>'.e($text['sub']).'</h2>',
            self::paragraph($paragraphs[1]),
            $quote,
            self::paragraph($paragraphs[2]),
            '<p><em>'.e(self::DISCLAIMER[$locale->value]).'</em></p>',
        ]);
    }

    private static function paragraph(string $text): string
    {
        return '<p>'.e($text).'</p>';
    }

    /** @return list<array<string, mixed>> */
    private static function egypt(): array
    {
        return [
            [
                'key' => 'riverside-promenade',
                'category' => 'egypt',
                'author' => 'journalist',
                'tags' => ['cities', 'environment'],
                'hours' => 5,
                'pop' => 9,
                'featured' => true,
                'ar' => [
                    'state' => 'published',
                    'title' => 'إعادة افتتاح كورنيش مدينة الريحان بعد أعمال التطوير',
                    'excerpt' => 'استقبل الكورنيش المطل على النيل آلاف الزائرين في يومه الأول بعد التطوير، بمسارات جديدة للمشاة وإضاءة بالطاقة الشمسية ومقاعد مظللة.',
                    'caption' => 'ممشى الكورنيش بعد التطوير (صورة توضيحية)',
                    'intro' => 'أعادت محافظة الريحان افتتاح الكورنيش المطل على النيل أمام الزائرين صباح اليوم، بعد ثمانية أشهر من أعمال التطوير شملت رصف المسارات وتركيب الإضاءة وزراعة آلاف الشتلات.',
                    'paras' => [
                        'وشهد اليوم الأول إقبالاً لافتاً من الأسر والشباب، إذ امتلأت المقاعد المظللة على امتداد الممشى منذ الساعات الأولى، وظهرت طوابير قصيرة أمام أكشاك المشروبات التي خُصصت لأصحاب المشروعات الصغيرة من أبناء المدينة.',
                        'خُصص مسار مستقل للمشاة وآخر للدراجات بطول ثلاثة كيلومترات، يفصله عن حركة السيارات حاجز نباتي منخفض. وركّبت أعمدة إنارة تعمل بالطاقة الشمسية تكفي لإضاءة الممشى حتى ساعة متأخرة من الليل، إلى جانب دورات مياه ومواقف مخصصة لذوي الإعاقة.',
                        'ومن المقرر أن تُقام في الكورنيش فعاليات أسبوعية للأسرة خلال الأسابيع المقبلة، على أن تعلن المحافظة جدولها عبر صفحاتها الرسمية. وتدرس الجهات المعنية تعميم التصميم على مدن أخرى تطل على النيل.',
                    ],
                    'sub' => 'تفاصيل التطوير',
                    'quote' => '«أردنا مكاناً يشبه أهل المدينة، يجلسون فيه مطمئنين ويرون النهر كما ينبغي أن يُرى»',
                    'by' => 'ليلى عبد الحميد، منسقة المشروع',
                ],
                'en' => [
                    'state' => 'published',
                    'title' => 'Rayhan City riverside promenade reopens after renovation',
                    'excerpt' => 'Thousands of visitors walked the renovated Nile-side promenade on its first day, with new pedestrian lanes, solar lighting and shaded seating.',
                    'caption' => 'The promenade after renovation (illustration)',
                    'intro' => 'Rayhan Governorate reopened its Nile-side promenade to visitors this morning, after eight months of works that covered new paving, lighting and thousands of planted saplings.',
                    'paras' => [
                        'Families and young people turned out in numbers on the first day. Shaded benches along the full length of the walk filled up early, and short queues formed at the drink kiosks set aside for small businesses from the city.',
                        'A three-kilometre pedestrian lane and a separate cycle lane now run alongside the water, divided from the road by a low planted barrier. Solar-powered lamp posts light the walk late into the night, and there are new restrooms and reserved parking for visitors with disabilities.',
                        'Weekly family events are planned on the promenade over the coming weeks, with the governorate due to announce the schedule on its official pages. Officials are also considering applying the design to other Nile-side towns.',
                    ],
                    'sub' => 'What has changed',
                    'quote' => '“We wanted a place that looks like the people of the city: somewhere to sit without worry and see the river as it deserves to be seen.”',
                    'by' => 'Laila Abdelhamid, project coordinator',
                ],
            ],
            [
                'key' => 'school-meals-pilot',
                'category' => 'egypt',
                'author' => 'journalist2',
                'tags' => ['education', 'health'],
                'hours' => 30,
                'pop' => 5,
                'ar' => [
                    'state' => 'published',
                    'title' => 'توسيع تجربة الوجبات المدرسية لتشمل أربعين مدرسة',
                    'excerpt' => 'بدأت المرحلة الثانية من برنامج الوجبات المدرسية في مدارس ابتدائية بثلاث محافظات، مع التركيز على مكونات محلية وقوائم طعام متوازنة.',
                    'caption' => 'وجبة مدرسية ضمن البرنامج التجريبي (صورة توضيحية)',
                    'intro' => 'بدأ برنامج الوجبات المدرسية التجريبي مرحلته الثانية هذا الأسبوع، ليصل إلى أربعين مدرسة ابتدائية في ثلاث محافظات بعد أن اقتصر في مرحلته الأولى على اثنتي عشرة مدرسة.',
                    'paras' => [
                        'وتُقدَّم الوجبة يومياً قبل الحصة الثالثة، وتتألف من خبز وبقوليات وخضروات موسمية وحصة من الفاكهة. وقال القائمون على البرنامج إن القوائم وُضعت بمشاركة أخصائيي تغذية وراعت اختلاف الاحتياجات بين التلاميذ.',
                        'يُشترى جزء كبير من المكونات من تعاونيات زراعية قريبة من كل مدرسة، بما يقلل تكلفة النقل ويدعم الإنتاج المحلي. وتُحضَّر الوجبات في مطابخ مجهزة تخضع لتفتيش دوري يُعلن ملخصه لأولياء الأمور.',
                        'ويتضمن التقييم المقرر في نهاية العام الدراسي قياس معدلات الحضور ورضا أولياء الأمور وتكلفة الوجبة الواحدة، تمهيداً لاتخاذ قرار بشأن التوسع في العام التالي.',
                    ],
                    'sub' => 'كيف تُدار العملية؟',
                    'quote' => '«لاحظنا أن التلاميذ صاروا أكثر نشاطاً في الحصص الأخيرة، وأن الغياب انخفض بعد أسابيع قليلة من بدء البرنامج»',
                    'by' => 'هالة سمير، مديرة مدرسة ابتدائية',
                ],
                'en' => [
                    'state' => 'published',
                    'title' => 'School meals pilot expands to forty schools',
                    'excerpt' => 'The second phase of the school meals programme has started in primary schools across three governorates, built on local ingredients and balanced menus.',
                    'caption' => 'A meal served under the pilot programme (illustration)',
                    'intro' => 'The school meals pilot began its second phase this week, reaching forty primary schools in three governorates after a first phase limited to twelve.',
                    'paras' => [
                        'A meal is served every day before the third lesson, made up of bread, pulses, seasonal vegetables and a portion of fruit. Programme staff said the menus were drawn up with nutritionists and take account of the different needs of pupils.',
                        'A large share of the ingredients is bought from farming co-operatives close to each school, which cuts transport costs and supports local produce. Meals are prepared in equipped kitchens that are inspected regularly, with a summary shared with parents.',
                        'The evaluation planned for the end of the school year will measure attendance, parent satisfaction and the cost of a single meal, ahead of a decision on further expansion next year.',
                    ],
                    'sub' => 'How it is run',
                    'quote' => '“We noticed pupils were livelier in the last lessons of the day, and absences fell within a few weeks of the programme starting.”',
                    'by' => 'Hala Samir, primary school principal',
                ],
            ],
            [
                'key' => 'north-coast-rain-alert',
                'category' => 'egypt',
                'author' => 'editor',
                'tags' => ['weather'],
                'hours' => 2,
                'pop' => 10,
                'breaking' => 'active',
                'ar' => [
                    'state' => 'published',
                    'title' => 'تنبيه من أمطار غزيرة على الساحل الشمالي حتى صباح الغد',
                    'excerpt' => 'دعت الجهات المعنية سكان المناطق الساحلية إلى توخي الحذر وتجنب الطرق المكشوفة ليلاً، مع استمرار الأمطار المتوقعة حتى صباح الغد.',
                    'caption' => 'سحب ممطرة فوق الساحل (صورة توضيحية)',
                    'intro' => 'أصدرت جهات الإنذار المبكر تنبيهاً من أمطار غزيرة متوقعة على الساحل الشمالي تبدأ مساء اليوم وتستمر حتى صباح الغد، وسط توقعات برياح نشطة وانخفاض في مستوى الرؤية.',
                    'paras' => [
                        'وأوصت الجهات المعنية السكان بتجنب السير على الطرق المكشوفة خلال ساعات الليل، وإبعاد السيارات عن الأماكن المنخفضة، وتأمين الأشياء المعرضة للسقوط على الأسطح والشرفات.',
                        'رُفعت درجة الاستعداد في غرف العمليات بالمدن الساحلية، وجرى دفع معدات شفط المياه إلى نقاط التجمع المعروفة. وأكدت فرق الصيانة أن مجاري تصريف الأمطار خضعت للتنظيف قبل بدء الموسم.',
                        'ويُنتظر أن تتراجع شدة الأمطار تدريجياً بعد منتصف نهار الغد، على أن يصدر تحديث جديد للتنبيه مع أول ضوء.',
                    ],
                    'sub' => 'الاستعدادات الجارية',
                    'quote' => '«نتابع الخريطة على مدار الساعة، وأي تغيير في مسار السحب سيُعلن فوراً عبر قنواتنا الرسمية»',
                    'by' => 'ياسر منير، متحدث باسم غرفة العمليات',
                ],
                'en' => [
                    'state' => 'published',
                    'title' => 'Heavy-rain alert issued for the north coast until tomorrow morning',
                    'excerpt' => 'Residents of coastal areas are urged to take care and avoid exposed roads overnight, with rain expected to continue until tomorrow morning.',
                    'caption' => 'Rain clouds over the coast (illustration)',
                    'intro' => 'Early-warning bodies have issued an alert for heavy rain on the north coast, starting this evening and lasting until tomorrow morning, with active winds and reduced visibility expected.',
                    'paras' => [
                        'Residents were advised to avoid exposed roads after dark, keep cars away from low-lying spots, and secure loose objects on roofs and balconies.',
                        'Coastal cities have raised their readiness level in operations rooms, and water-pumping equipment has been moved to known gathering points. Maintenance crews said storm drains were cleared before the season began.',
                        'Rain is expected to ease gradually after midday tomorrow, with a fresh update to the alert due at first light.',
                    ],
                    'sub' => 'Preparations under way',
                    'quote' => '“We are watching the map around the clock, and any change in the path of the clouds will be announced at once on our official channels.”',
                    'by' => 'Yasser Mounir, operations room spokesperson',
                ],
            ],
        ];
    }

    /** @return list<array<string, mixed>> */
    private static function politics(): array
    {
        return [
            [
                'key' => 'digital-archive-bill',
                'category' => 'politics',
                'author' => 'journalist',
                'tags' => ['parliament', 'technology'],
                'hours' => 52,
                'pop' => 6,
                'ar' => [
                    'state' => 'published',
                    'title' => 'لجنة برلمانية تبدأ مناقشة مشروع قانون الأرشيف الرقمي',
                    'excerpt' => 'تستمع اللجنة المختصة إلى أمناء المحفوظات والخبراء القانونيين قبل إعداد تقريرها حول حفظ الوثائق الحكومية إلكترونياً.',
                    'caption' => 'قاعة اجتماعات اللجنة (صورة توضيحية)',
                    'intro' => 'بدأت لجنة برلمانية متخصصة أولى جلسات مناقشة مشروع قانون الأرشيف الرقمي، الذي يضع قواعد موحدة لحفظ الوثائق الحكومية إلكترونياً وإتاحتها للباحثين والمواطنين.',
                    'paras' => [
                        'واستمعت اللجنة في جلستها الافتتاحية إلى عرض من الجهة المُعِدّة للمشروع، تناول مدد الحفظ المقترحة وتصنيف الوثائق حسب درجة الحساسية، وآلية النقل التدريجي للأرشيف الورقي القائم.',
                        'تركزت المناقشات على ثلاثة محاور هي حماية البيانات الشخصية، وتكاليف التحول الرقمي، وحق الباحثين في الاطلاع. وطلب عدد من الأعضاء تقديم دراسة مقارنة لتجارب دول أخرى قبل الجلسة المقبلة.',
                        'وتعتزم اللجنة عقد جلسات استماع مع أمناء المحفوظات والمتخصصين في القانون وتقنية المعلومات، على أن تُعد تقريرها خلال الأسابيع المقبلة وتحيله إلى الجلسة العامة.',
                    ],
                    'sub' => 'محاور النقاش',
                    'quote' => '«الأرشيف ذاكرة الدولة، وحُسن حفظه يعني حُسن محاسبتها وحُسن تعلُّم الأجيال منها»',
                    'by' => 'رشا فؤاد، عضو اللجنة',
                ],
                'en' => [
                    'state' => 'published',
                    'title' => 'Parliamentary committee begins debating digital archive bill',
                    'excerpt' => 'The committee will hear archivists and legal experts before drafting its report on keeping government records electronically.',
                    'caption' => 'The committee meeting room (illustration)',
                    'intro' => 'A specialised parliamentary committee held the first sessions on the digital archive bill, which sets common rules for keeping government records electronically and making them available to researchers and citizens.',
                    'paras' => [
                        'At its opening session the committee heard a presentation from the drafting body covering proposed retention periods, the sorting of records by sensitivity, and a phased plan for moving the existing paper archive.',
                        'Discussion centred on three areas: protection of personal data, the cost of going digital, and researchers’ right of access. Several members asked for a comparative study of other countries’ experience before the next session.',
                        'The committee plans hearings with archivists and specialists in law and information technology, and expects to finish its report in the coming weeks before sending it to the general session.',
                    ],
                    'sub' => 'Points of debate',
                    'quote' => '“An archive is the memory of the state; keeping it well means holding it to account well and learning from it well.”',
                    'by' => 'Rasha Fouad, committee member',
                ],
            ],
            [
                'key' => 'council-meeting-calendars',
                'category' => 'politics',
                'author' => 'journalist2',
                'tags' => ['cities', 'technology'],
                'hours' => 76,
                'pop' => 3,
                'ar' => [
                    'state' => 'published',
                    'title' => 'المجالس المحلية تنشر مواعيد اجتماعاتها على الإنترنت',
                    'excerpt' => 'تتيح المنصة الجديدة للمواطنين الاطلاع على جداول الاجتماعات وجداول الأعمال ومحاضر الجلسات السابقة في موضع واحد.',
                    'caption' => 'واجهة المنصة الجديدة (صورة توضيحية)',
                    'intro' => 'أطلقت وحدة الإدارة المحلية منصة إلكترونية تنشر فيها المجالس المحلية مواعيد اجتماعاتها وجداول أعمالها، لتتيح للمواطنين متابعة ما يُناقش في أحيائهم دون الحاجة إلى زيارة المقرات.',
                    'paras' => [
                        'وتتضمن المنصة تقويماً مبسطاً يمكن تصفحه من الهاتف، وخانة بحث بالحي أو الموضوع، ورابطاً لتنزيل محاضر الجلسات السابقة بصيغة مفتوحة تسهّل على الصحفيين والباحثين الرجوع إليها.',
                        'يبدأ العمل بالمنصة في عشرة مجالس تجريبية، ثم تنضم بقية المجالس تباعاً خلال الربع المقبل. وخضع الموظفون المعنيون بالنشر لدورات تدريبية قصيرة حول إعداد المحاضر وحماية البيانات.',
                        'وتخطط الوحدة لإضافة خدمة التنبيه بمواعيد اجتماعات كل حي في مرحلة لاحقة، بعد تقييم أداء المنصة خلال الأشهر الأولى من التشغيل.',
                    ],
                    'sub' => 'خطوات التطبيق',
                    'quote' => '«النشر المنتظم لمواعيد الاجتماعات يمنح الناس فرصة حقيقية للمشاركة، ويجعل عملنا أوضح وأسهل في المتابعة»',
                    'by' => 'أيمن شحاتة، رئيس مجلس محلي تجريبي',
                ],
                'en' => [
                    'state' => 'in_review',
                    'title' => 'Local councils to publish meeting calendars online',
                    'excerpt' => 'The new platform lets residents see meeting schedules, agendas and past minutes in one place.',
                    'caption' => 'The new platform’s home page (illustration)',
                    'intro' => 'The local administration unit has launched an online platform where local councils publish their meeting dates and agendas, so residents can follow what is discussed in their districts without visiting the offices.',
                    'paras' => [
                        'The platform includes a simple calendar that works on a phone, a search box by district or topic, and downloadable minutes of past sessions in an open format that makes them easier for journalists and researchers to use.',
                        'Ten pilot councils start using the platform first, and the rest follow in turn during the next quarter. Staff responsible for publishing have taken short courses on preparing minutes and protecting personal data.',
                        'The unit plans to add alerts for each district’s meeting dates in a later phase, once the platform’s first months of operation have been reviewed.',
                    ],
                    'sub' => 'Rollout',
                    'quote' => '“Regular publication of meeting dates gives people a real chance to take part, and makes our work clearer and easier to follow.”',
                    'by' => 'Ayman Shehata, chair of a pilot local council',
                ],
            ],
            [
                'key' => 'statistics-release-calendar',
                'category' => 'politics',
                'author' => 'journalist',
                'tags' => ['economy'],
                'hours' => 3,
                'pop' => 0,
                'hero' => false,
                'ar' => [
                    'state' => 'draft',
                    'title' => 'شرح مبسط: كيف يُحدَّد موعد إصدار البيانات الاقتصادية الشهرية؟',
                    'excerpt' => 'يوضح هذا الشرح الخطوات التي تمر بها الأرقام الرسمية قبل نشرها، ولماذا تُعلن مواعيدها مسبقاً.',
                    'caption' => null,
                    'intro' => 'تصدر الجهات الإحصائية في معظم الدول بياناتها الاقتصادية وفق جدول معلن مسبقاً، وتُراجَع الأرقام عدة مرات قبل أن تصل إلى القارئ. فما الذي يحدث خلف الكواليس؟',
                    'paras' => [
                        'تبدأ الدورة بجمع البيانات من مصادرها، كالشركات والمؤسسات الحكومية والمسوح الميدانية، ثم تُنقَّى وتُقارَن بالفترات السابقة للتأكد من خلوها من الأخطاء الظاهرة.',
                        'إعلان الجدول مسبقاً يمنح الجميع الفرصة نفسها للاطلاع على الأرقام في اللحظة ذاتها، ويقلل احتمال تسريبها أو استغلالها قبل نشرها، كما يساعد الصحفيين والباحثين على التخطيط لتغطياتهم.',
                        'وسنضيف في النسخة النهائية من هذا الشرح رسماً توضيحياً لمراحل المراجعة وروابط لجداول الإصدار الرسمية.',
                    ],
                    'sub' => 'لماذا تُعلن المواعيد مسبقاً؟',
                    'quote' => '«الثقة في الأرقام تبدأ من الثقة في طريقة إصدارها»',
                    'by' => 'سلوى رضوان، أستاذة الإحصاء',
                ],
            ],
        ];
    }

    /** @return list<array<string, mixed>> */
    private static function business(): array
    {
        return [
            [
                'key' => 'logistics-startup-fund',
                'category' => 'business',
                'author' => 'journalist2',
                'tags' => ['economy', 'technology'],
                'hours' => 20,
                'pop' => 8,
                'featured' => true,
                'ar' => [
                    'state' => 'published',
                    'title' => 'صندوق لدعم الشركات الناشئة يموّل ثماني شركات في الخدمات اللوجستية',
                    'excerpt' => 'اختار الصندوق ثماني شركات ناشئة تعمل في النقل والتخزين والتوصيل لتتلقى تمويلاً أولياً ودعماً فنياً على مدى عام.',
                    'caption' => 'فريق إحدى الشركات الناشئة المشاركة (صورة توضيحية)',
                    'intro' => 'أعلن صندوق لدعم الشركات الناشئة اختيار ثماني شركات تعمل في مجال الخدمات اللوجستية لتتلقى تمويلاً أولياً ودعماً فنياً على مدى اثني عشر شهراً، من بين أكثر من مئة وخمسين طلباً.',
                    'paras' => [
                        'وتتنوع الشركات المختارة بين منصات لحجز الشاحنات، وحلول لتتبع الشحنات، ومشروعات تخزين ذكية في المدن الجديدة. وقال الصندوق إن معايير الاختيار شملت جدوى النموذج التجاري وقدرة الفريق على التوسع.',
                        'يحصل كل مشروع على تمويل أولي، ويشارك في برنامج إرشاد مع خبراء في سلاسل الإمداد، ويُتاح له الوصول إلى شبكة من الموزعين والمصنعين لتجربة منتجاته على نطاق فعلي.',
                        'ومن المقرر أن تعرض الشركات نتائجها أمام المستثمرين في يوم ختامي بعد عام، وأن يفتح الصندوق باب التقديم لدفعة جديدة في الربع الأول من العام المقبل.',
                    ],
                    'sub' => 'ما الذي يشمله الدعم؟',
                    'quote' => '«سلاسل الإمداد هي العمود الفقري للاقتصاد، وأي تحسين صغير فيها ينعكس على الأسعار وعلى سرعة وصول السلع»',
                    'by' => 'ميرفت النجار، مديرة الاستثمار في الصندوق',
                ],
                'en' => [
                    'state' => 'published',
                    'title' => 'Start-up fund backs eight logistics companies',
                    'excerpt' => 'The fund picked eight start-ups working in transport, storage and delivery to receive seed funding and technical support over a year.',
                    'caption' => 'The team at one of the selected start-ups (illustration)',
                    'intro' => 'A start-up support fund has announced it is backing eight logistics companies with seed funding and technical support over twelve months, chosen from more than 150 applications.',
                    'paras' => [
                        'The selected firms range from truck-booking platforms to shipment-tracking tools and smart storage projects in the new cities. The fund said its criteria included the viability of each business model and the team’s ability to grow.',
                        'Each project receives seed funding, joins a mentoring programme with supply-chain experts, and gets access to a network of distributors and manufacturers where it can test its product at real scale.',
                        'The companies are due to present their results to investors at a closing day in a year’s time, and the fund will open applications for a new cohort in the first quarter of next year.',
                    ],
                    'sub' => 'What the support includes',
                    'quote' => '“Supply chains are the backbone of the economy, and any small improvement in them shows up in prices and in how fast goods arrive.”',
                    'by' => 'Mervat El-Naggar, investment director at the fund',
                ],
            ],
            [
                'key' => 'grain-storage-plan',
                'category' => 'business',
                'author' => 'journalist',
                'tags' => ['economy', 'trade'],
                'hours' => 6,
                'pop' => 0,
                'ar' => [
                    'state' => 'in_review',
                    'title' => 'خطة لزيادة الطاقة التخزينية للحبوب في الصوامع الحديثة',
                    'excerpt' => 'تستهدف الخطة رفع الطاقة الاستيعابية للصوامع وتحسين شروط التخزين للحد من الفاقد خلال موسم الحصاد.',
                    'caption' => 'صومعة حديثة لتخزين الحبوب (صورة توضيحية)',
                    'intro' => 'كشفت جهات معنية بالإمداد الغذائي عن خطة لزيادة الطاقة التخزينية للحبوب في الصوامع الحديثة بنحو الخمس خلال العامين المقبلين، بهدف تقليل الفاقد وتنظيم التوريد.',
                    'paras' => [
                        'وتشمل الخطة إنشاء صوامع جديدة في ثلاثة مواقع قرب مناطق الإنتاج، وتحديث أنظمة التهوية والمراقبة في الصوامع القائمة، وربطها بمنصة إلكترونية تتابع الكميات الواردة والمصروفة.',
                        'وتشير تقديرات أولية إلى أن تحسين شروط التخزين قد يخفض الفاقد بنسبة ملحوظة، وهو ما ينعكس على التكلفة النهائية وعلى قدرة السوق على امتصاص تقلبات الأسعار في مواسم الحصاد.',
                        'ويُتوقع أن تُطرح المواقع الجديدة للتنفيذ على مراحل، مع إعلان شروط المناقصات تباعاً عبر المنصات الرسمية بعد اعتماد الخطة.',
                    ],
                    'sub' => 'الفاقد وأثره',
                    'quote' => '«التخزين الجيد لا يقل أهمية عن الإنتاج الجيد، فالحبة التي تُفقد بعد الحصاد جهد ضائع كله»',
                    'by' => 'عادل بدران، مستشار في الإمداد الغذائي',
                ],
                'en' => [
                    'state' => 'in_review',
                    'title' => 'Plan to raise grain storage capacity at modern silos',
                    'excerpt' => 'The plan aims to raise silo capacity and improve storage conditions to cut losses during the harvest season.',
                    'caption' => 'A modern grain silo (illustration)',
                    'intro' => 'Food-supply bodies have set out a plan to raise grain storage capacity at modern silos by about a fifth over the next two years, aiming to reduce losses and organise deliveries.',
                    'paras' => [
                        'The plan covers new silos at three sites near production areas, upgraded ventilation and monitoring at existing silos, and a digital platform linking them that tracks the quantities received and released.',
                        'Early estimates suggest better storage conditions could cut losses noticeably, which would show up in final costs and in the market’s ability to absorb price swings in harvest seasons.',
                        'The new sites are expected to go out for construction in stages, with tender conditions announced one after another on official platforms once the plan is approved.',
                    ],
                    'sub' => 'Losses and their cost',
                    'quote' => '“Good storage matters as much as good production: a grain lost after harvest is all that effort wasted.”',
                    'by' => 'Adel Badran, food-supply adviser',
                ],
            ],
            [
                'key' => 'citrus-export-season',
                'category' => 'business',
                'author' => 'journalist2',
                'tags' => ['trade'],
                'hours' => 120,
                'pop' => 3,
                'ar' => [
                    'state' => 'published',
                    'title' => 'المصدّرون يتوقعون استقرار الطلب الخارجي على الحمضيات هذا الموسم',
                    'excerpt' => 'أظهرت أولى الشحنات المتجهة إلى الأسواق الخارجية طلباً مستقراً، مع توقعات بأن يظل حجم الصادرات قريباً من العام الماضي.',
                    'caption' => 'فرز الحمضيات قبل التصدير (صورة توضيحية)',
                    'intro' => 'يتوقع مصدّرو الحمضيات أن يظل الطلب الخارجي مستقراً هذا الموسم، بعدما غادرت الشحنات الأولى الموانئ نحو أسواق آسيا وأوروبا بأسعار قريبة من مستويات الموسم الماضي.',
                    'paras' => [
                        'وقال عدد من المصدرين إن جودة المحصول جاءت جيدة هذا العام بفضل اعتدال الطقس في فترة الإزهار، ما ساعد على ارتفاع نسبة الثمار المطابقة لمواصفات التصدير.',
                        'وما زالت تكاليف الشحن والتعبئة تمثل تحدياً، إضافة إلى الحاجة إلى تسريع إجراءات الفحص في الموانئ، حتى تصل الشحنات إلى وجهاتها وهي في أفضل حالاتها.',
                        'ويأمل المصدرون في فتح أسواق جديدة خلال الموسم عبر المشاركة في المعارض الدولية، وتوقيع عقود طويلة الأجل مع كبار المستوردين.',
                    ],
                    'sub' => 'التحديات القائمة',
                    'quote' => '«المنافسة في الأسواق الخارجية تُحسم بالجودة والالتزام بالمواعيد، وكلاهما في أيدينا إذا تيسرت الإجراءات»',
                    'by' => 'سهير الخولي، مصدِّرة حمضيات',
                ],
            ],
        ];
    }

    /** @return list<array<string, mixed>> */
    private static function regionalAffairs(): array
    {
        return [
            [
                'key' => 'port-schedule-sharing',
                'category' => 'regional-affairs',
                'author' => 'journalist',
                'tags' => ['shipping', 'trade'],
                'hours' => 100,
                'pop' => 4,
                'ar' => [
                    'state' => 'published',
                    'title' => 'مشغّلو الموانئ يتفقون على تبادل بيانات الجداول الملاحية',
                    'excerpt' => 'اتفقت شركات تشغيل الموانئ في المنطقة على نشر جداول وصول السفن وتحديثها لحظياً لتقليل أوقات الانتظار.',
                    'caption' => 'رافعات ميناء على البحر المتوسط (صورة توضيحية)',
                    'intro' => 'اتفقت شركات تشغيل عدد من الموانئ الإقليمية على تبادل بيانات جداول وصول السفن ومواعيد الرسو بصورة لحظية، في خطوة تهدف إلى تقليل أوقات الانتظار وتحسين تخطيط الشحن.',
                    'paras' => [
                        'ويقضي الاتفاق بإنشاء منصة مشتركة تُحدَّث تلقائياً من أنظمة كل ميناء، ويطّلع عليها شركات الملاحة والوكلاء الملاحيون ومقدمو الخدمات اللوجستية دون رسوم في مرحلتها الأولى.',
                        'ويرى متخصصون أن توافر المعلومة المبكرة عن ازدحام الموانئ يتيح لشركات الشحن تعديل مساراتها في الوقت المناسب، ويخفض استهلاك الوقود، ويقلل الانبعاثات الناتجة عن الانتظار في عرض البحر.',
                        'وتبدأ المنصة التشغيل التجريبي بين ثلاثة موانئ خلال شهرين، على أن تنضم بقية الموانئ المشاركة تدريجياً بعد اختبار جودة البيانات.',
                    ],
                    'sub' => 'أثر متوقع على الشحن',
                    'quote' => '«حين تتبادل الموانئ معلوماتها، يربح الجميع: السفن والبضائع والمستهلك الذي ينتظر السلعة على الرف»',
                    'by' => 'كمال الصاوي، خبير ملاحة',
                ],
                'en' => [
                    'state' => 'published',
                    'title' => 'Port operators agree to share shipping schedule data',
                    'excerpt' => 'Operators of ports across the region have agreed to publish vessel arrival schedules in real time to cut waiting times.',
                    'caption' => 'Quayside cranes at a Mediterranean port (illustration)',
                    'intro' => 'Operators of several regional ports have agreed to share vessel arrival schedules and berthing times in real time, a step meant to shorten waiting times and improve shipping plans.',
                    'paras' => [
                        'The agreement sets up a shared platform that updates automatically from each port’s systems, open without charge in its first phase to shipping lines, shipping agents and logistics providers.',
                        'Specialists say early information on port congestion lets carriers adjust routes in time, lowers fuel use, and cuts the emissions caused by ships waiting offshore.',
                        'The platform starts a trial between three ports within two months, with the remaining ports joining gradually once data quality has been tested.',
                    ],
                    'sub' => 'Expected effect on shipping',
                    'quote' => '“When ports share their information everyone gains: the ships, the cargo and the shopper waiting for goods on the shelf.”',
                    'by' => 'Kamal El-Sawy, shipping analyst',
                ],
            ],
            [
                'key' => 'power-grid-link-study',
                'category' => 'regional-affairs',
                'author' => 'journalist2',
                'tags' => ['energy'],
                'hours' => 150,
                'pop' => 2,
                'ar' => [
                    'state' => 'published',
                    'title' => 'دراسة الربط الكهربائي الإقليمي تدخل مرحلتها الثانية',
                    'excerpt' => 'انتقلت دراسة الجدوى لخط ربط الشبكات الكهربائية بين دول الجوار إلى مرحلة تقييم الأثر البيئي والتكلفة التفصيلية.',
                    'caption' => 'أبراج نقل الكهرباء (صورة توضيحية)',
                    'intro' => 'دخلت دراسة جدوى مشروع الربط الكهربائي الإقليمي مرحلتها الثانية، بعد أن أنهى الفريق الفني مراجعة المسارات المقترحة وتحديد أنسب نقاط الاتصال بين الشبكات.',
                    'paras' => [
                        'وتركز المرحلة الجديدة على تقدير التكلفة التفصيلية للخط وتقييم أثره البيئي والاجتماعي على المناطق التي يمر بها، إضافة إلى دراسة الأطر التنظيمية اللازمة لتبادل الطاقة بين الأطراف.',
                        'يقول المشاركون في الدراسة إن الربط قد يتيح تبادل الفائض في أوقات الذروة ويحسن استقرار الشبكة، لكنهم يشددون على أن الفوائد النهائية ستعتمد على اتفاقات التسعير التي لم تُحسم بعد.',
                        'ومن المنتظر أن تُعرض نتائج المرحلة الثانية على اللجنة العليا للمشروع في الربع المقبل، تمهيداً لاتخاذ قرار بشأن الانتقال إلى مرحلة التصميم.',
                    ],
                    'sub' => 'ما الذي سيتغير للمستهلك؟',
                    'quote' => '«لا نريد مشروعاً يُبنى على الأمل، بل على أرقام دقيقة تثبت جدواه لجميع الأطراف»',
                    'by' => 'نبيل عزمي، مهندس في الفريق الفني',
                ],
                'en' => [
                    'state' => 'changes_requested',
                    'note' => 'Please double-check the figures in the second paragraph against the study summary and add the date the second phase started before resubmitting.',
                    'title' => 'Regional power-grid link study enters second phase',
                    'excerpt' => 'The feasibility study for a line joining neighbouring countries’ power grids has moved on to environmental impact and detailed costing.',
                    'caption' => 'Power transmission towers (illustration)',
                    'intro' => 'The feasibility study for a regional power-grid link has entered its second phase, after the technical team finished reviewing the proposed routes and picking the best connection points between networks.',
                    'paras' => [
                        'The new phase focuses on a detailed cost estimate for the line and an assessment of its environmental and social impact on the areas it crosses, as well as the regulatory framework needed for the parties to trade electricity.',
                        'People involved in the study say the link could allow surplus power to be traded at peak times and make the grid more stable, but stress that the final benefits will depend on pricing agreements that are not yet settled.',
                        'The results of the second phase are due to go to the project’s steering committee next quarter, ahead of a decision on moving to the design phase.',
                    ],
                    'sub' => 'What will change for consumers?',
                    'quote' => '“We do not want a project built on hope, but on precise figures that prove it works for every party.”',
                    'by' => 'Nabil Azmy, engineer on the technical team',
                ],
            ],
            [
                'key' => 'sandstorm-flight-delays',
                'category' => 'regional-affairs',
                'author' => 'journalist',
                'tags' => ['travel', 'weather'],
                'hours' => 60,
                'pop' => 7,
                'breaking' => 'expired',
                'ar' => [
                    'state' => 'published',
                    'title' => 'انتظام حركة الطيران بعد تأخيرات بسبب عاصفة رملية',
                    'excerpt' => 'عادت الرحلات إلى جداولها المعتادة في مطارات المنطقة بعد ساعات من التأخير الذي سببته عاصفة رملية مفاجئة.',
                    'caption' => 'طائرات على مدرج المطار (صورة توضيحية)',
                    'intro' => 'عادت حركة الطيران إلى طبيعتها في عدد من مطارات المنطقة بعد ساعات من التأخير والتحويلات سببتها عاصفة رملية مفاجئة قلّلت مستوى الرؤية في الساعات الأولى من الصباح.',
                    'paras' => [
                        'وأوضحت شركات الطيران أن معظم الرحلات المتأثرة أُعيدت جدولتها خلال اليوم نفسه، فيما جرى تحويل عدد محدود منها إلى مطارات بديلة لحين تحسن الأحوال الجوية.',
                        'ونصحت إدارات المطارات الركاب بمتابعة حالة رحلاتهم عبر المواقع الرسمية لشركات الطيران قبل التوجه إلى المطار، وبالاحتفاظ بوثائق الحجز في حال الحاجة إلى إعادة الجدولة أو استرداد المبالغ.',
                        'وتتوقع الأرصاد استقرار الأحوال خلال الساعات المقبلة، مع إمكانية تجدد نشاط الرياح في نهاية الأسبوع.',
                    ],
                    'sub' => 'نصائح للمسافرين',
                    'quote' => '«سلامة الركاب أولاً، وأي تأخير نتخذه هو قرار مدروس لتجنب مخاطر أكبر»',
                    'by' => 'عمرو لطفي، متحدث باسم شركة طيران',
                ],
                'en' => [
                    'state' => 'published',
                    'title' => 'Air traffic returns to normal after sandstorm delays',
                    'excerpt' => 'Flights are back on their usual schedules at regional airports after hours of delay caused by a sudden sandstorm.',
                    'caption' => 'Aircraft on an airport apron (illustration)',
                    'intro' => 'Air traffic has returned to normal at several regional airports after hours of delays and diversions caused by a sudden sandstorm that cut visibility in the early morning.',
                    'paras' => [
                        'Airlines said most affected flights were rescheduled within the same day, while a small number were diverted to alternate airports until the weather improved.',
                        'Airport authorities advised passengers to check their flight status on airlines’ official sites before leaving for the airport, and to keep booking documents in case they need to rebook or claim refunds.',
                        'Forecasters expect conditions to settle over the coming hours, though winds could pick up again at the weekend.',
                    ],
                    'sub' => 'Advice for travellers',
                    'quote' => '“Passenger safety comes first, and any delay we take is a considered decision to avoid bigger risks.”',
                    'by' => 'Amr Lotfy, airline spokesperson',
                ],
            ],
        ];
    }

    /** @return list<array<string, mixed>> */
    private static function world(): array
    {
        return [
            [
                'key' => 'global-shipping-costs-ease',
                'category' => 'world',
                'author' => 'journalist2',
                'tags' => ['shipping', 'economy'],
                'hours' => 190,
                'pop' => 5,
                'ar' => [
                    'state' => 'published',
                    'title' => 'تكاليف الشحن البحري العالمية تتراجع للشهر الثاني على التوالي',
                    'excerpt' => 'سجلت مؤشرات أسعار الحاويات انخفاضاً جديداً مع تحسن انسيابية الموانئ وزيادة المعروض من السفن.',
                    'caption' => 'حاويات شحن في ميناء كبير (صورة توضيحية)',
                    'intro' => 'واصلت مؤشرات تكاليف الشحن البحري العالمية تراجعها للشهر الثاني على التوالي، مدفوعة بتحسن انسيابية الموانئ وزيادة المعروض من السفن مقارنة بالطلب.',
                    'paras' => [
                        'وأشار محللون إلى أن تراجع الأسعار شمل معظم الخطوط الرئيسية بين آسيا وأوروبا والبحر المتوسط، وإن ظلت الأسعار أعلى من مستوياتها قبل سنوات قليلة.',
                        'ينعكس انخفاض تكلفة الشحن تدريجياً على أسعار السلع المستوردة، لكن التأثير قد يستغرق أسابيع قبل أن يظهر في الأسواق المحلية، نظراً لتعاقدات الاستيراد القائمة ومخزون التجار.',
                        'ويراقب المستوردون التطورات عن كثب قبل تحديد حجم طلبياتهم للأشهر المقبلة، في ظل توقعات بأن تستقر الأسعار عند مستويات أدنى نسبياً.',
                    ],
                    'sub' => 'ماذا يعني ذلك للأسواق؟',
                    'quote' => '«الاتجاه إيجابي، لكن الحذر مطلوب، فأي اضطراب مفاجئ في الممرات الملاحية قد يعكس المسار سريعاً»',
                    'by' => 'ليديا مرسي، محللة اقتصادية',
                ],
                'en' => [
                    'state' => 'published',
                    'title' => 'Global shipping costs ease for a second month',
                    'excerpt' => 'Container price indices fell again as ports ran more smoothly and ship supply grew.',
                    'caption' => 'Shipping containers at a large port (illustration)',
                    'intro' => 'Global sea-freight cost indices fell for a second month running, helped by smoother port operations and more ship capacity relative to demand.',
                    'paras' => [
                        'Analysts noted the decline covered most major routes between Asia, Europe and the Mediterranean, though prices remain above their levels of a few years ago.',
                        'Lower freight costs feed gradually into the prices of imported goods, but the effect can take weeks to reach local markets because of existing import contracts and traders’ stock.',
                        'Importers are watching developments closely before deciding the size of their orders for the coming months, with expectations that prices will settle at relatively lower levels.',
                    ],
                    'sub' => 'What this means for markets',
                    'quote' => '“The trend is positive, but caution is needed: a sudden disruption on the shipping lanes could reverse it quickly.”',
                    'by' => 'Lydia Morsy, economic analyst',
                ],
            ],
            [
                'key' => 'heat-adaptation-experts',
                'category' => 'world',
                'author' => 'editor',
                'tags' => ['environment', 'cities'],
                'hours' => 300,
                'pop' => 0,
                'ar' => [
                    'state' => 'archived',
                    'title' => 'أبرز ما دار في اجتماع خبراء المناخ حول التكيف مع الحرارة',
                    'excerpt' => 'ملخص لأهم التوصيات التي خرج بها اجتماع الخبراء حول تكيف المدن مع موجات الحرارة.',
                    'caption' => 'شارع مظلل في مدينة كبيرة (صورة توضيحية)',
                    'intro' => 'اختتم خبراء في علوم المناخ والتخطيط العمراني اجتماعاً استمر يومين، ناقشوا خلاله طرق تكيف المدن مع موجات الحرارة المتكررة وحماية الفئات الأكثر عرضة للخطر.',
                    'paras' => [
                        'وأوصى المشاركون بزيادة المساحات الخضراء والظل في الأحياء المزدحمة، واستخدام مواد عاكسة للحرارة في الأسطح والطرق، وإنشاء نقاط تبريد عامة تعمل في أوقات الذروة.',
                        'وشددت التوصيات على أهمية الإنذار المبكر بموجات الحر، ووضع خطط خاصة لكبار السن والعمال الذين يعملون في الهواء الطلق، وتدريب العاملين في الرعاية الصحية على التعامل مع الإجهاد الحراري.',
                        'وتقررت أرشفة هذا الملخص بعد صدور التقرير الكامل للاجتماع، الذي يتضمن تفاصيل التوصيات وجداولها الزمنية.',
                    ],
                    'sub' => 'توصيات للمدن',
                    'quote' => '«المدينة التي تُصمَّم للظل والهواء تدفع أقل من المدينة التي تضطر لمعالجة الحر بعد وقوعه»',
                    'by' => 'مها الدسوقي، باحثة في التخطيط العمراني',
                ],
                'en' => [
                    'state' => 'archived',
                    'title' => 'Key points from the climate experts’ meeting on adapting to heat',
                    'excerpt' => 'A summary of the main recommendations from the expert meeting on how cities can adapt to heatwaves.',
                    'caption' => 'A shaded street in a large city (illustration)',
                    'intro' => 'Experts in climate science and urban planning ended a two-day meeting on how cities can adapt to repeated heatwaves and protect the people most at risk.',
                    'paras' => [
                        'Participants recommended more green space and shade in crowded districts, heat-reflective materials on roofs and roads, and public cooling points that run at peak hours.',
                        'The recommendations stressed early warning of heatwaves, special plans for older people and outdoor workers, and training for health workers in dealing with heat stress.',
                        'This summary was archived once the meeting’s full report was published, with detailed recommendations and timetables.',
                    ],
                    'sub' => 'Recommendations for cities',
                    'quote' => '“A city designed for shade and airflow pays less than one forced to treat the heat after it arrives.”',
                    'by' => 'Maha El-Desouky, urban planning researcher',
                ],
            ],
            [
                'key' => 'technical-standards-forum',
                'category' => 'world',
                'author' => 'admin',
                'tags' => ['technology'],
                'hours' => -30,
                'pop' => 0,
                'ar' => [
                    'state' => 'scheduled',
                    'title' => 'افتتاح منتدى المعايير التقنية الدولي الأسبوع المقبل',
                    'excerpt' => 'يجمع المنتدى متخصصين من عدة دول لمناقشة معايير الأمن الرقمي وتبادل البيانات بين الجهات المختلفة.',
                    'caption' => 'قاعة المؤتمر قبيل الافتتاح (صورة توضيحية)',
                    'intro' => 'تنطلق الأسبوع المقبل أعمال منتدى المعايير التقنية الدولي، بمشاركة متخصصين من عدة دول لمناقشة معايير الأمن الرقمي وتبادل البيانات بين الجهات الحكومية والخاصة.',
                    'paras' => [
                        'ويتضمن جدول المنتدى ثلاث جلسات رئيسية وسبع ورش عمل متخصصة، تتناول الهوية الرقمية وحماية البيانات والتشغيل البيني بين الأنظمة، إضافة إلى معرض مصاحب للشركات الناشئة.',
                        'يشارك في الفعاليات ممثلون عن هيئات المواصفات والجامعات وشركات التقنية، ويُتاح حضور الجلسات العامة للمهتمين بعد التسجيل المسبق عبر الموقع الرسمي للمنتدى.',
                        'ويُختتم المنتدى بإصدار وثيقة توصيات تُعرض على الجهات المشاركة لاعتمادها كإطار استرشادي.',
                    ],
                    'sub' => 'من يشارك؟',
                    'quote' => '«المعايير المشتركة تفتح الباب أمام تعاون أوسع، وتوفر على الجميع جهداً ومالاً في تكرار الحلول»',
                    'by' => 'هدى عثمان، منسقة البرنامج',
                ],
                'en' => [
                    'state' => 'scheduled',
                    'title' => 'International technical standards forum opens next week',
                    'excerpt' => 'The forum brings together specialists from several countries to discuss digital security and data-exchange standards.',
                    'caption' => 'The conference hall before the opening (illustration)',
                    'intro' => 'The international technical standards forum opens next week, with specialists from several countries discussing digital security and data-exchange standards between government and private bodies.',
                    'paras' => [
                        'The programme includes three main sessions and seven specialised workshops on digital identity, data protection and interoperability between systems, plus an accompanying exhibition of start-ups.',
                        'Representatives of standards bodies, universities and technology companies will attend, and interested members of the public can join the general sessions after registering in advance on the forum’s official site.',
                        'The forum ends with a recommendations document submitted to participating bodies to adopt as a guiding framework.',
                    ],
                    'sub' => 'Who is taking part',
                    'quote' => '“Shared standards open the door to wider co-operation and save everyone effort and money repeating solutions.”',
                    'by' => 'Hoda Osman, programme coordinator',
                ],
            ],
        ];
    }

    /** @return list<array<string, mixed>> */
    private static function culture(): array
    {
        return [
            [
                'key' => 'short-film-festival',
                'category' => 'culture',
                'author' => 'journalist',
                'tags' => ['cinema'],
                'hours' => 40,
                'pop' => 6,
                'ar' => [
                    'state' => 'published',
                    'title' => 'مهرجان الأفلام القصيرة يعلن قائمة المشاركين في دورته الجديدة',
                    'excerpt' => 'تضم القائمة أربعين فيلماً من ثماني عشرة دولة، مع عروض مجانية في ثلاث قاعات خلال أيام المهرجان.',
                    'caption' => 'ملصق دعائي للمهرجان (صورة توضيحية)',
                    'intro' => 'أعلن مهرجان الأفلام القصيرة قائمة الأعمال المشاركة في دورته الجديدة، وتضم أربعين فيلماً من ثماني عشرة دولة، في مسابقات للروائي والتسجيلي والرسوم المتحركة.',
                    'paras' => [
                        'وتقام العروض على مدى ستة أيام في ثلاث قاعات، مع حفلات توقيع وندوات مفتوحة يلتقي فيها الجمهور بصناع الأفلام، وتُعرض الأفلام العربية مترجمةً إلى الإنجليزية.',
                        'وخصص المهرجان برنامجاً لطلاب السينما يشمل ورش عمل في كتابة السيناريو والمونتاج والتصوير يقدمها متخصصون، إضافة إلى جلسات لعرض المشروعات الأولى على لجنة من المنتجين.',
                        'وتُحجز التذاكر المجانية عبر الموقع الرسمي أو من شباك القاعات قبل العرض بساعة، حتى اكتمال المقاعد.',
                    ],
                    'sub' => 'برنامج مخصص للشباب',
                    'quote' => '«الفيلم القصير مساحة حرة للتجريب، ومنه تخرج أصوات جديدة تستحق أن تُسمع»',
                    'by' => 'ندى يوسف، مديرة المهرجان',
                ],
                'en' => [
                    'state' => 'published',
                    'title' => 'Short film festival announces line-up for its new edition',
                    'excerpt' => 'The line-up has forty films from eighteen countries, with free screenings in three halls over the festival days.',
                    'caption' => 'A festival promotional poster (illustration)',
                    'intro' => 'The short film festival has announced the line-up for its new edition: forty films from eighteen countries, competing in fiction, documentary and animation.',
                    'paras' => [
                        'Screenings run over six days in three halls, alongside signings and open talks where audiences meet the filmmakers, and Arabic films are shown with English subtitles.',
                        'The festival has set aside a programme for film students with workshops on screenwriting, editing and cinematography led by specialists, plus sessions to pitch first projects to a panel of producers.',
                        'Free tickets can be booked on the official site or at the hall box offices an hour before each screening, while seats last.',
                    ],
                    'sub' => 'A programme for young people',
                    'quote' => '“A short film is a free space for experiment, and new voices that deserve to be heard come out of it.”',
                    'by' => 'Nada Youssef, festival director',
                ],
            ],
            [
                'key' => 'museum-night',
                'category' => 'culture',
                'author' => 'journalist2',
                'tags' => ['museums'],
                'hours' => 220,
                'pop' => 4,
                'hero' => false,
                'ar' => [
                    'state' => 'published',
                    'title' => 'ليلة المتاحف تمدّ ساعات الزيارة إلى منتصف الليل',
                    'excerpt' => 'تفتح عدة متاحف أبوابها ليلاً مع جولات إرشادية وعروض موسيقية في أمسية ثقافية مفتوحة للجميع.',
                    'caption' => null,
                    'intro' => 'تفتح عدة متاحف وقاعات عرض أبوابها للجمهور حتى منتصف الليل ضمن «ليلة المتاحف» التي تنظمها جهات ثقافية لتقريب الفنون والتاريخ من الأسر والشباب.',
                    'paras' => [
                        'وتتضمن الليلة جولات إرشادية قصيرة كل نصف ساعة، وعروضاً موسيقية حية في الساحات، وورشاً للأطفال في الرسم والحرف التقليدية، بتذكرة رمزية تُخصَّص حصيلتها لصيانة المقتنيات.',
                        'تشارك في الفعالية متاحف للفنون والتاريخ الطبيعي والحياة الشعبية، إضافة إلى معارض جديدة تُقام في مبانٍ تراثية أُعيد ترميمها. وتوفر الجهات المنظمة حافلات مجانية بين المواقع الرئيسية.',
                        'ويُنصح الزوار بالحضور مبكراً لتجنب الازدحام، مع الاطلاع على جدول الجولات وأوقات الورش عبر الصفحات الرسمية للجهات المشاركة.',
                    ],
                    'sub' => 'أماكن المشاركة',
                    'quote' => '«أجمل ما في الليلة أن ترى الأسرة كلها تقف أمام قطعة قديمة وتبدأ في طرح الأسئلة»',
                    'by' => 'فاطمة جمال، أمينة متحف',
                ],
                'en' => [
                    'state' => 'published',
                    'title' => 'Museum Night extends visiting hours to midnight',
                    'excerpt' => 'Several museums open their doors at night with guided tours and live music for a cultural evening open to all.',
                    'caption' => null,
                    'intro' => 'Several museums and galleries will open to the public until midnight as part of “Museum Night”, organised by cultural bodies to bring art and history closer to families and young people.',
                    'paras' => [
                        'The evening includes short guided tours every half hour, live music in courtyards, and workshops for children in drawing and traditional crafts, with a token ticket whose proceeds go towards conserving collections.',
                        'Art, natural history and folk-life museums are taking part, along with new exhibitions in restored heritage buildings. The organisers are providing free buses between the main sites.',
                        'Visitors are advised to arrive early to avoid crowds and to check tour and workshop times on the participating bodies’ official pages.',
                    ],
                    'sub' => 'Where to go',
                    'quote' => '“The best part of the night is watching a whole family stand in front of an old object and start asking questions.”',
                    'by' => 'Fatma Gamal, museum curator',
                ],
            ],
            [
                'key' => 'translation-prize-shortlist',
                'category' => 'culture',
                'author' => 'journalist',
                'tags' => ['books'],
                'hours' => 280,
                'pop' => 2,
                'ar' => [
                    'state' => 'published',
                    'title' => 'إعلان القائمة القصيرة لجائزة الترجمة الأدبية',
                    'excerpt' => 'تضم القائمة القصيرة ستة أعمال مترجمة بين العربية ولغات أخرى، على أن تُعلن الجائزة في حفل ختامي الشهر المقبل.',
                    'caption' => 'أغلفة كتب مترجمة (صورة توضيحية)',
                    'intro' => 'أعلنت لجنة تحكيم جائزة الترجمة الأدبية قائمتها القصيرة التي تضم ستة أعمال مترجمة بين العربية ولغات أخرى، اختيرت من بين أكثر من ستين عملاً قُدّمت هذا العام.',
                    'paras' => [
                        'وقالت اللجنة إن الأعمال المختارة تتميز بدقة النقل وجمال الأسلوب واحترام روح النص الأصلي، وتتنوع بين الرواية والشعر والمسرح وكتب الأطفال.',
                        'اعتمد المحكّمون على مقارنة الترجمة بالنص الأصلي، وتقييم سلاسة اللغة، ومراعاة السياق الثقافي، إضافة إلى مقدمات المترجمين التي توضح اختياراتهم في مواجهة الصعوبات اللغوية.',
                        'وسيُكرَّم الفائزون في حفل ختامي الشهر المقبل، مع إتاحة مقتطفات من الأعمال المرشحة للقراءة على الموقع الرسمي للجائزة.',
                    ],
                    'sub' => 'معايير التحكيم',
                    'quote' => '«الترجمة الجيدة جسر يعبر عليه القارئ إلى عالم آخر دون أن يشعر أنه غادر لغته»',
                    'by' => 'وائل مختار، عضو لجنة التحكيم',
                ],
                'en' => [
                    'state' => 'draft',
                    'title' => 'Literary translation prize announces its shortlist',
                    'excerpt' => 'Six works translated between Arabic and other languages make the shortlist, with the winners due at a closing ceremony next month.',
                    'caption' => 'Covers of translated books (illustration)',
                    'intro' => 'The jury of the literary translation prize has announced a shortlist of six works translated between Arabic and other languages, picked from more than sixty entries this year.',
                    'paras' => [
                        'The jury said the chosen works stand out for accuracy of rendering, beauty of style and respect for the spirit of the original, and range across novels, poetry, theatre and children’s books.',
                        'Judges compared each translation with its original, weighed the fluency of the language, considered the cultural context, and read the translators’ introductions explaining how they handled linguistic difficulties.',
                        'The winners will be honoured at a closing ceremony next month, with extracts from the shortlisted works available to read on the prize’s official site.',
                    ],
                    'sub' => 'Judging criteria',
                    'quote' => '“A good translation is a bridge the reader crosses into another world without feeling they have left their own language.”',
                    'by' => 'Wael Mokhtar, jury member',
                ],
            ],
        ];
    }
}
