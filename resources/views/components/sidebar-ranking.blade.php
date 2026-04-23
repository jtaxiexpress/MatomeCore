@php
    use App\Models\Site;

    // Determine current App context
    $appSlug = request()->route('app');

    $query = Site::where('is_active', true);

    if ($appSlug) {
        $query->whereHas('app', function ($q) use ($appSlug) {
            $q->where('api_slug', $appSlug);
        });
    }

    $topSites = $query->orderByDesc('daily_in_count')
        ->orderByDesc('id')
        ->limit(10)
        ->get();
@endphp

<div class="sticky top-20 flex flex-col gap-4">
    <div class="rounded-xl border border-border/40 bg-surface-elevated p-4 shadow-sm dark:border-border-dark/40 dark:bg-surface-elevated-dark">
        <h3 class="mb-4 flex items-center gap-2 text-sm font-bold text-text-primary dark:text-white">
            <span class="text-accent">👑</span>
            人気ブログランキング
        </h3>

        <ul class="flex flex-col gap-3">
            @forelse ($topSites as $index => $site)
                <li class="flex items-center gap-3">
                    @if ($index === 0)
                        <span class="flex size-6 shrink-0 items-center justify-center rounded-full bg-yellow-400/20 text-[11px] font-bold text-yellow-600 dark:bg-yellow-400/10 dark:text-yellow-400">
                            1
                        </span>
                    @elseif ($index === 1)
                        <span class="flex size-6 shrink-0 items-center justify-center rounded-full bg-slate-300/30 text-[11px] font-bold text-slate-600 dark:bg-slate-300/10 dark:text-slate-300">
                            2
                        </span>
                    @elseif ($index === 2)
                        <span class="flex size-6 shrink-0 items-center justify-center rounded-full bg-amber-600/20 text-[11px] font-bold text-amber-700 dark:bg-amber-600/10 dark:text-amber-500">
                            3
                        </span>
                    @else
                        <span class="flex size-6 shrink-0 items-center justify-center rounded-full bg-surface text-[11px] font-bold text-text-secondary dark:bg-surface-dark dark:text-text-tertiary">
                            {{ $index + 1 }}
                        </span>
                    @endif

                    <a href="{{ $site->url }}" target="_blank" rel="noopener noreferrer" class="min-w-0 flex-1 transition-opacity hover:opacity-70">
                        <p class="truncate text-[13px] font-medium text-text-primary dark:text-white">
                            {{ $site->name }}
                        </p>
                        <p class="mt-0.5 text-[10px] text-text-tertiary">
                            IN: {{ number_format($site->daily_in_count) }}
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
