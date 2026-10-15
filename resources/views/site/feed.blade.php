{!! '<?xml version="1.0" encoding="UTF-8"?>' !!}
<rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom">
    <channel>
        <title>{{ $title }}</title>
        <link>{{ $link }}</link>
        <description>{{ $description }}</description>
        <language>{{ $language }}</language>
        <lastBuildDate>{{ $lastBuild }}</lastBuildDate>
        <atom:link href="{{ $selfLink }}" rel="self" type="application/rss+xml"/>
        <image>
            <url>{{ $logo }}</url>
            <title>{{ $title }}</title>
            <link>{{ $link }}</link>
            <width>144</width>
            <height>40</height>
        </image>
@foreach ($items as $item)
        <item>
            <title>{{ $item['title'] }}</title>
            <link>{{ $item['link'] }}</link>
            <guid isPermaLink="true">{{ $item['link'] }}</guid>
            <pubDate>{{ $item['pubDate'] }}</pubDate>
            <category>{{ $item['category'] }}</category>
            <description>{{ $item['description'] }}</description>
@if ($item['enclosure'])
            <enclosure url="{{ $item['enclosure']['url'] }}" length="{{ $item['enclosure']['length'] }}" type="{{ $item['enclosure']['type'] }}"/>
@endif
        </item>
@endforeach
    </channel>
</rss>
