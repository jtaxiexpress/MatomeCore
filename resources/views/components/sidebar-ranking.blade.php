@php
    use App\Models\Site;
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
            ->get(['id', 'name', 'url', 'traffic_score'])
            ->toArray();
    });
@endphp

<div class="sticky top-20 flex flex-col gap-4">
    <div class="rounded-xl border border-border/40 bg-surface-elevated p-4 shadow-sm dark:border-border-dark/40 dark:bg-surface-elevated-dark">
        <h3 class="mb-4 flex items-center gap-2 text-sm font-bold text-text-primary dark:text-white">
            <span class="text-accent">👑</span>
            人気ブログランキング
        </h3>

        <ul class="flex flex-col gap-3">
            @forelse ($topSites as $site)
                <li class="flex items-center gap-3">
                    @if ($loop->index === 0)
                        <span class="flex size-6 shrink-0 items-center justify-center rounded-full bg-yellow-400/20 text-[11px] font-bold text-yellow-600 dark:bg-yellow-400/10 dark:text-yellow-400">
                            1
                        </span>
                    @elseif ($loop->index === 1)
                        <span class="flex size-6 shrink-0 items-center justify-center rounded-full bg-slate-300/30 text-[11px] font-bold text-slate-600 dark:bg-slate-300/10 dark:text-slate-300">
                            2
                        </span>
                    @elseif ($loop->index === 2)
                        <span class="flex size-6 shrink-0 items-center justify-center rounded-full bg-amber-600/20 text-[11px] font-bold text-amber-700 dark:bg-amber-600/10 dark:text-amber-500">
                            3
                        </span>
                    @else
                        <span class="flex size-6 shrink-0 items-center justify-center rounded-full bg-surface text-[11px] font-bold text-text-secondary dark:bg-surface-dark dark:text-text-tertiary">
                            {{ $loop->iteration }}
                        </span>
                    @endif

                    <a href="{{ $site['url'] }}" target="_blank" rel="noopener noreferrer" class="min-w-0 flex-1 transition-opacity hover:opacity-70">
                        <p class="truncate text-[13px] font-medium text-text-primary dark:text-white">
                            {{ $site['name'] }}
                        </p>
                        <p class="mt-0.5 text-[10px] text-text-tertiary">
                            📈 {{ number_format($site['traffic_score'] ?? 0) }} pts
                        </p>
                    </a>
                </li>
            @empty
                <li class="text-xs text-text-tertiary">
                    データがありません
                </li>
            @endforelse
        </ul>
    </div>
</div>
