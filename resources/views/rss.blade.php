<?xml version="1.0" encoding="UTF-8"?>
<rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom">
    <channel>
        <title>{{ $title }}</title>
        <link>{{ $link }}</link>
        <description>{{ $description }}</description>
        <language>ja</language>
        <lastBuildDate>{{ now()->toRfc2822String() }}</lastBuildDate>
        @foreach($articles as $article)
            <item>
                <title><![CDATA[{{ $article->title }}]]></title>
                <link>{{ route('front.go', ['app' => $article->app_id ?? $app->id ?? 1, 'article' => $article->id]) }}</link>
                <guid isPermaLink="true">{{ route('front.go', ['app' => $article->app_id ?? $app->id ?? 1, 'article' => $article->id]) }}</guid>
                <pubDate>{{ $article->published_at->toRfc2822String() }}</pubDate>
                <description><![CDATA[{{ $article->summary ?? '' }}]]></description>
                @if($article->site)
                    <author>{{ $article->site->name }}</author>
                @endif
            </item>
        @endforeach
    </channel>
</rss>
