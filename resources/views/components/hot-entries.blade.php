@props(['targetApp' => null])

@php
    $query = \App\Models\Article::query()
        ->with(['site:id,name'])
        ->where('daily_out_count', '>', 0)
        ->trafficFiltered()
        ->orderByDesc('daily_out_count')
        ->limit(5);

    if ($targetApp instanceof \App\Models\App) {
        $query->whereBelongsTo($targetApp);
    }

    $hotEntries = $query->get();
@endphp

@if($hotEntries->isNotEmpty())
    <div class="mb-8 overflow-hidden rounded-2xl border border-border/40 bg-surface-elevated shadow-sm dark:border-border-dark/40 dark:bg-surface-elevated-dark">
        <div class="flex items-center gap-2 border-b border-border/40 bg-black/[0.02] px-4 py-3 dark:border-border-dark/40 dark:bg-white/[0.02]">
            <svg class="size-5 text-red-500" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" d="M15.362 5.214A8.252 8.252 0 0112 21 8.25 8.25 0 016.038 7.048 8.287 8.287 0 009 9.6a8.983 8.983 0 013.361-6.866 8.21 8.21 0 003 2.48z" />
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 18a3.75 3.75 0 00.495-7.467 5.99 5.99 0 00-1.925 3.546 5.974 5.974 0 01-2.133-1A3.75 3.75 0 0012 18z" />
            </svg>
            <h2 class="text-sm font-bold tracking-tight text-text-primary dark:text-white">注目の記事 (HOT)</h2>
        </div>
        <div class="divide-y divide-border/40 dark:divide-border-dark/40">
            @foreach($hotEntries as $index => $article)
                <a x-show="!mutedSites.includes({{ $article->site_id ?? 'null' }})" href="{{ route('front.go', ['app' => $article->app_id ?? $targetApp?->id ?? 1, 'article' => $article->id]) }}" target="_blank" rel="noopener noreferrer" class="group flex items-start gap-4 p-4 transition-colors hover:bg-black/[0.02] dark:hover:bg-white/[0.02]">
                    <div class="flex size-6 shrink-0 items-center justify-center rounded-full bg-black/5 text-xs font-bold text-text-secondary dark:bg-white/10 dark:text-text-tertiary">
                        {{ $index + 1 }}
                    </div>
                    <div class="min-w-0 flex-1">
                        <h3 class="text-sm font-medium leading-snug text-text-primary group-hover:text-accent dark:text-white dark:group-hover:text-accent">
                            {{ $article->title }}
                        </h3>
                        <div class="mt-2 flex items-center gap-3 text-xs text-text-tertiary">
                            @if($article->site)
                                <span class="truncate">{{ $article->site->name }}</span>
                            @endif
                            <span class="flex items-center gap-1" title="本日のクリック数">
                                <svg class="size-3" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M15 11.25l-3-3m0 0l-3 3m3-3v7.5M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                                </svg>
                                {{ number_format($article->daily_out_count) }}
                            </span>
                        </div>
                    </div>
                </a>
            @endforeach
        </div>
    </div>
@endif
