<?php

return [
    'skip' => 'انتقل إلى المحتوى',
    'menu' => 'القائمة',
    'home' => 'الرئيسية',
    'sections' => 'الأقسام',
    'primary_nav' => 'التصفح الرئيسي',
    'search' => 'بحث',
    'search_label' => 'ابحث في الموقع',
    'search_placeholder' => 'ابحث في الأخبار',
    'breaking' => 'عاجل',
    'breaking_label' => 'الأخبار العاجلة',
    'live' => 'مباشر',
    'by' => 'بقلم :name',
    'top_stories' => 'أهم الأخبار',
    'latest' => 'آخر الأخبار',
    'most_read' => 'الأكثر قراءة',
    'tabs_label' => 'الأكثر قراءة وآخر الأخبار',
    'empty' => [
        'title' => 'قصصنا الأولى في الطريق',
        'text' => 'نجهّز الآن أولى تقاريرنا. عُد إلينا بعد قليل.',
    ],
    // Labels of the masthead credits beside the logo and in the footer; the names are set in the panel.
    'masthead' => [
        'chairman' => 'رئيس مجلس الإدارة',
        'editor' => 'رئيس التحرير',
    ],
    // The editor's label stretched with tatweel so its line ends with the chairman's beside the logo (measured in IBM Plex
    // Sans Arabic Bold: 8 + 16 tatweel). Shown there only; screen readers and the footer get the plain label above.
    'masthead_fitted' => [
        'editor' => 'رئيــــــــس التحريــــــــــــــــر',
    ],
    'footer' => [
        'about' => 'موقع إخباري يضع مصر في بؤرة الضوء، ويقدّم لك أهم الأخبار والتحليلات من مصر والمنطقة والعالم بلغة واضحة.',
        'follow' => 'تابعنا',
        'contact' => 'للتواصل',
        'rss' => 'خلاصة RSS',
        'pages' => 'عن الموقع',
        // The LRM keeps the full stop after the Latin site name instead of flipping to its left.
        'rights' => "© :year :name.\u{200E} جميع الحقوق محفوظة.",
    ],
    'newsletter' => [
        'title' => 'النشرة البريدية',
        'text' => 'انضم إلى قائمة نشرتنا البريدية المرتقبة.',
        'email' => 'البريد الإلكتروني',
        'submit' => 'اشترك',
    ],
    'pagination' => [
        'label' => 'تصفح الصفحات',
        'previous' => 'السابق',
        'next' => 'التالي',
        'page' => 'صفحة :page',
    ],
    'pages' => [
        'breadcrumb' => 'مسار التنقل',
        'updated' => 'آخر تحديث: :date',
        // Replaces the [[contact]] token in a page body while no contact address is configured.
        'editorial_team' => 'فريق التحرير',
    ],
    'errors' => [
        'home' => 'العودة إلى الصفحة الرئيسية',
        'newsroom' => 'العودة إلى غرفة الأخبار',
        '403' => ['title' => 'ليست لديك صلاحية', 'text' => 'هذه الصفحة غير متاحة لحسابك. إن كنت تحتاج إليها فاطلب ذلك من المسؤول.'],
        '404' => ['title' => 'الصفحة غير موجودة', 'text' => 'ربما نُقلت الصفحة أو حُذفت، أو أن الرابط غير صحيح.'],
        '419' => ['title' => 'انتهت صلاحية الصفحة', 'text' => 'عُد إلى الصفحة السابقة وحدّثها ثم حاول مرة أخرى.'],
        '429' => ['title' => 'طلبات كثيرة', 'text' => 'يرجى الانتظار قليلًا ثم المحاولة مرة أخرى.'],
        '500' => ['title' => 'حدث خطأ ما', 'text' => 'نعتذر عن ذلك. حاول مرة أخرى بعد قليل.'],
        '503' => ['title' => 'الموقع قيد الصيانة', 'text' => 'نجري تحديثًا سريعًا وسنعود خلال وقت قصير.'],
    ],
];
