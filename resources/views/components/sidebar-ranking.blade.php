@php
    use App\Models\Site;
    use App\Models\Article;
    use Illuminate\Support\Facades\Cache;

    // Determine current App context
    $app = request()->route('app');
    $appId = $app instanceof \App\Models\App ? $app->id : null;

    $cacheKey = $appId ? "ranking_app_{$appId}" : "ranking_app_all";

    $topSites = Cache::remember($cacheKey, 60 * 5, function () use ($appId) {
        $query = Site::where('is_active', true);

        if ($appId) {
            $query->where('app_id', $appId);
        }

        return $query->orderByDesc('traffic_score')
            ->orderByDesc('id')
            ->limit(10)
            ->get(['id', 'name', 'url', 'traffic_score', 'daily_in_count', 'daily_out_count'])
            ->toArray();
    });

    // Hot entries for sidebar
    $hotQuery = Article::query()
        ->with(['site:id,name', 'app:id,name,api_slug'])
        ->where('daily_out_count', '>', 0)
        ->trafficFiltered()
        ->orderByDesc('daily_out_count')
        ->limit(5);

    if ($appId) {
        $hotQuery->where('app_id', $appId);
    }

    $sidebarHotEntries = $hotQuery->get(['id', 'app_id', 'site_id', 'title', 'daily_out_count', 'published_at']);
@endphp

<div class="sticky top-20 flex flex-col gap-3">
    {{-- Ranking card --}}
    <div class="rounded-xl border border-border/40 bg-surface-elevated p-4 shadow-sm dark:border-border-dark/40 dark:bg-surface-elevated-dark">
        <h3 class="mb-3 flex items-center gap-2 text-[13px] font-bold text-text-primary dark:text-white">
            <span class="text-accent">👑</span>
            人気ブログランキング
        </h3>

        <ul class="flex flex-col gap-2.5">
            @forelse ($topSites as $site)
                <li class="flex items-start gap-2">
                    @if ($loop->index === 0)
                        <span class="mt-0.5 flex size-5 shrink-0 items-center justify-center rounded-full bg-yellow-400/20 text-[10px] font-bold text-yellow-600 dark:bg-yellow-400/10 dark:text-yellow-400">
                            1
                        </span>
                    @elseif ($loop->index === 1)
                        <span class="mt-0.5 flex size-5 shrink-0 items-center justify-center rounded-full bg-slate-300/30 text-[10px] font-bold text-slate-600 dark:bg-slate-300/10 dark:text-slate-300">
                            2
                        </span>
                    @elseif ($loop->index === 2)
                        <span class="mt-0.5 flex size-5 shrink-0 items-center justify-center rounded-full bg-amber-600/20 text-[10px] font-bold text-amber-700 dark:bg-amber-600/10 dark:text-amber-500">
                            3
                        </span>
                    @else
                        <span class="mt-0.5 flex size-5 shrink-0 items-center justify-center rounded-full bg-surface text-[10px] font-bold text-text-secondary dark:bg-surface-dark dark:text-text-tertiary">
                            {{ $loop->iteration }}
                        </span>
                    @endif

                    <div class="min-w-0 flex-1">
                        <a href="{{ $site['url'] }}" target="_blank" rel="noopener noreferrer" class="block truncate text-[12px] font-medium text-text-primary transition-opacity hover:opacity-70 dark:text-white">
                            {{ $site['name'] }}
                        </a>
                        {{-- IN / OUT / PT を横一列で表示 --}}
                        <div class="mt-0.5 flex items-center gap-2.5 text-[10px]">
                            <span class="text-blue-500 dark:text-blue-400" title="本日のIN数">
                                IN <span class="font-semibold">{{ number_format($site['daily_in_count'] ?? 0) }}</span>
                            </span>
                            <span class="text-border/60 dark:text-border-dark/60">·</span>
                            <span class="text-orange-500 dark:text-orange-400" title="本日のOUT数">
                                OUT <span class="font-semibold">{{ number_format($site['daily_out_count'] ?? 0) }}</span>
                            </span>
                            <span class="text-border/60 dark:text-border-dark/60">·</span>
                            <span class="text-text-tertiary" title="トラフィックスコア">
                                PT <span class="font-semibold">{{ number_format($site['traffic_score'] ?? 0) }}</span>
                            </span>
                        </div>
                    </div>
                </li>
            @empty
                <li class="text-xs text-text-tertiary">
                    データがありません
                </li>
            @endforelse
        </ul>
    </div>

    {{-- Hot entries (compact) --}}
    @if($sidebarHotEntries->isNotEmpty())
        <div class="rounded-xl border border-border/40 bg-surface-elevated px-3 py-2.5 shadow-sm dark:border-border-dark/40 dark:bg-surface-elevated-dark">
            <h4 class="mb-2 flex items-center gap-1 text-[11px] font-bold uppercase tracking-wide text-text-secondary dark:text-text-tertiary">
                <svg class="size-3 text-red-500" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M15.362 5.214A8.252 8.252 0 0112 21 8.25 8.25 0 016.038 7.048 8.287 8.287 0 009 9.6a8.983 8.983 0 013.361-6.866 8.21 8.21 0 003 2.48z" />
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 18a3.75 3.75 0 00.495-7.467 5.99 5.99 0 00-1.925 3.546 5.974 5.974 0 01-2.133-1A3.75 3.75 0 0012 18z" />
                </svg>
                注目の記事
            </h4>
            <ul class="flex flex-col divide-y divide-border/30 dark:divide-border-dark/30">
                @foreach($sidebarHotEntries as $hotArticle)
                    <li class="py-1.5 first:pt-0 last:pb-0">
                        <a
                            href="{{ route('front.go', ['app' => $hotArticle->app->api_slug, 'article' => $hotArticle->id]) }}"
                            target="_blank"
                            rel="noopener noreferrer"
                            class="group flex items-start gap-1.5"
                        >
                            <span class="mt-0.5 shrink-0 text-[10px] font-bold text-text-tertiary">{{ $loop->iteration }}.</span>
                            <div class="min-w-0 flex-1">
                                <span class="block text-[11px] leading-snug text-text-primary line-clamp-2 transition-colors group-hover:text-accent dark:text-white dark:group-hover:text-accent">
                                    {{ $hotArticle->title }}
                                </span>
                                <div class="mt-0.5 flex flex-wrap items-center gap-x-1.5 gap-y-0 text-[9px] text-text-tertiary">
                                    @if($hotArticle->app)
                                        <span class="rounded bg-accent/10 px-1 py-px font-medium text-accent dark:text-accent">{{ $hotArticle->app->name }}</span>
                                    @endif
                                    @if($hotArticle->site)
                                        <span class="truncate max-w-[80px]">{{ $hotArticle->site->name }}</span>
                                    @endif
                                    @if($hotArticle->published_at)
                                        <span>{{ $hotArticle->published_at->translatedFormat('n/j H:i') }}</span>
                                    @endif
                                </div>
                            </div>
                        </a>
                    </li>
                @endforeach
            </ul>
        </div>
    @endif
</div>
