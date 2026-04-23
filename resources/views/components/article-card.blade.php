@props([
    'article',
    'showMomentum' => true,
])

@php
    $thumbnailUrl = $article->display_thumbnail_url ?? null;
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

    $isNew = $publishedAt && $publishedAt->diffInHours(now()) <= 2;
    $isHot = $clickCount >= 10 || ($article->site->traffic_score ?? 0) >= 50;
@endphp

<article class="group relative flex gap-3 rounded-xl bg-surface-elevated p-3 shadow-card transition-all duration-200 hover:shadow-card-hover active:scale-[0.98] dark:bg-surface-elevated-dark">
    {{-- Thumbnail --}}
    <a
        href="{{ $articleUrl }}"
        target="_blank"
        rel="noopener noreferrer"
        class="relative block size-20 shrink-0 overflow-hidden rounded-lg bg-border/30 dark:bg-border-dark/30 sm:size-24"
        id="article-thumb-{{ $article->id }}"
    >
        @if ($thumbnailUrl)
            <img
                src="{{ $thumbnailUrl }}"
                alt=""
                class="size-full object-cover transition-transform duration-300 group-hover:scale-105"
                loading="lazy"
                decoding="async"
            >
        @else
            <div class="flex size-full items-center justify-center text-2xl text-text-tertiary">
                📄
            </div>
        @endif
    </a>

    {{-- Content --}}
    <div class="flex min-w-0 flex-1 flex-col justify-between">
        {{-- Title --}}
        <div class="flex items-start gap-1">
            @if ($isNew)
                <span class="mt-0.5 shrink-0 rounded bg-red-500 px-1.5 py-0.5 text-[10px] font-bold text-white">NEW</span>
            @endif
            @if ($isHot)
                <span class="mt-0.5 shrink-0 rounded bg-orange-500 px-1.5 py-0.5 text-[10px] font-bold text-white">🔥HOT</span>
            @endif
            <a
                href="{{ $articleUrl }}"
                target="_blank"
                rel="noopener noreferrer"
                class="line-clamp-2 text-[13px] font-medium leading-snug text-text-primary transition-colors group-hover:text-accent sm:text-sm dark:text-white dark:group-hover:text-accent"
                id="article-title-{{ $article->id }}"
            >
                {{ $article->title }}
            </a>
        </div>

        {{-- Meta row --}}
        <div class="mt-1.5 flex items-center gap-2 text-[11px] text-text-secondary dark:text-text-tertiary">
            {{-- Site name & App name --}}
            @if ($siteName)
                <span class="max-w-[120px] truncate">
                    @if (request()->route('app') === null && $article->relationLoaded('app'))
                        <span class="text-accent">[{{ $article->app?->name }}]</span>
                    @endif
                    {{ $siteName }}
                </span>
                <span class="text-border dark:text-border-dark">·</span>
            @endif

            {{-- Published time --}}
            @if ($publishedAt)
                <time datetime="{{ $publishedAt->toISOString() }}" class="shrink-0">
                    {{ $publishedAt->diffForHumans() }}
                </time>
            @endif

            {{-- Momentum badge --}}
            @if ($showMomentum && $clickCount > 0)
                <span class="ml-auto inline-flex shrink-0 items-center gap-0.5 rounded-full bg-momentum/10 px-1.5 py-0.5 text-[10px] font-semibold text-momentum">
                    🔥 {{ $clickCount }}
                </span>
            @endif
        </div>
    </div>
</article>
