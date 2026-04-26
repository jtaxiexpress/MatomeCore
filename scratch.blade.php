@props([
    'article',
    'showMomentum' => true,
])

@php
    $siteName = $article->site?->name ?? '';
    $publishedAt = $article->published_at;
    $clickCount = $article->daily_out_count ?? 0;
    
    // We assume the active tenant app is available either globally or we can pass it if needed.
    // For the home page, the app route parameter is in the URL.
    $appSlug = request()->route('app') ?? $article->app?->api_slug;
    
    // Fallback if app is fundamentally missing
    if (! $appSlug && ! $article->relationLoaded('app')) {
        $article->load('app');
        $appSlug = $article->app?->api_slug;
    }
    
    $articleUrl = route('front.go', ['app' => $appSlug, 'article' => $article->id]);
    $siteUrl = route('front.go', ['app' => $appSlug, 'article' => $article->id, 'site_redirect' => 1]); // We'll implement this parameter

    $isNew = $publishedAt && $publishedAt->diffInHours(now()) <= 2;
    $isHot = $clickCount >= 10 || ($article->site->traffic_score ?? 0) >= 50;
@endphp

<article 
    x-show="!mutedSites.includes({{ $article->site_id ?? 'null' }})"
    x-transition.out.opacity.duration.300ms
    class="group relative flex items-center gap-2 py-1.5 border-b border-border/40 last:border-0 hover:bg-black/5 active:bg-black/10 transition-colors px-2 -mx-2 dark:border-border-dark/40 dark:hover:bg-white/5 dark:active:bg-white/10"
>
    {{-- Published time --}}
    @if ($publishedAt)
        <time datetime="{{ $publishedAt->toISOString() }}" class="shrink-0 w-11 text-right text-[11px] tabular-nums text-text-tertiary">
            {{ $publishedAt->format('H:i') }}
        </time>
    @endif

    {{-- Title & Link --}}
    <a
        href="{{ $articleUrl }}"
        target="_blank"
        rel="noopener noreferrer"
        class="flex-1 min-w-0 flex items-center gap-1.5"
        id="article-title-{{ $article->id }}"
    >
        @if ($isNew)
            <span class="shrink-0 text-[10px] font-bold text-red-500">NEW</span>
        @endif
        @if ($isHot)
            <span class="shrink-0 text-[10px] font-bold text-orange-500">HOT</span>
        @endif
        <span class="truncate text-[13px] sm:text-sm font-medium leading-tight text-text-primary group-hover:text-accent dark:text-white dark:group-hover:text-accent">
            {{ $article->title }}
        </span>
    </a>

    {{-- Site Name --}}
    @if ($siteName)
        <a 
            href="{{ $siteUrl }}" 
            target="_blank" 
            rel="noopener noreferrer"
            class="shrink-0 max-w-[80px] sm:max-w-[120px] truncate text-[11px] text-text-secondary hover:underline dark:text-text-tertiary"
        >
            @if (request()->route('app') === null && $article->relationLoaded('app'))
                <span class="text-accent">[{{ $article->app?->name }}]</span>
            @endif
            {{ $siteName }}
        </a>
    @endif
</article>
