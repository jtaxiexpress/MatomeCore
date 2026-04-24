<?php

use App\Models\App;
use App\Models\Site;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

new
#[Layout('layouts::app')]
class extends Component {
    /** @var string 'all'|api_slug */
    #[Url(as: 'app', keep: true)]
    public string $appFilter = 'all';

    /** @var string '24h'|'weekly'|'monthly' */
    #[Url(as: 'period', keep: true)]
    public string $period = '24h';

    /**
     * @return Collection<int, App>
     */
    #[Computed]
    public function apps(): Collection
    {
        return App::where('is_active', true)->orderBy('id')->get();
    }

    /**
     * @return Collection<int, Site>
     */
    #[Computed]
    public function rankedSites(): Collection
    {
        $query = Site::query()
            ->where('is_active', true)
            ->select([
                'sites.id',
                'sites.name',
                'sites.url',
                'sites.app_id',
                'sites.daily_in_count',
                'sites.daily_out_count',
                'sites.traffic_score',
            ]);

        // App filter
        if ($this->appFilter !== 'all') {
            $query->whereHas('app', fn ($q) => $q->where('api_slug', $this->appFilter));
        }

        // Period-based ordering
        if ($this->period === '24h') {
            $query->orderByRaw('(sites.daily_in_count + sites.daily_out_count) DESC');
        } else {
            // weekly / monthly both use the aggregated traffic_score
            $query->orderByDesc('sites.traffic_score');
        }

        return $query->orderByDesc('sites.id')->limit(100)->get();
    }

    public function setAppFilter(string $slug): void
    {
        $this->appFilter = $slug;
    }

    public function setPeriod(string $period): void
    {
        if (in_array($period, ['24h', 'weekly', 'monthly'], true)) {
            $this->period = $period;
        }
    }
}; ?>

<div>
    @section('title', 'ブログランキング')
    @section('tenant_name', config('app.name'))

    <div class="mx-auto max-w-3xl">
        {{-- Page header --}}
        <div class="mb-6">
            <h1 class="text-2xl font-bold tracking-tight text-text-primary dark:text-white">
                📈 ブログランキング
            </h1>
            <p class="mt-1 text-sm text-text-secondary dark:text-text-tertiary">
                当アンテナにご登録いただいているサイト様のアクセスランキングです。
            </p>
        </div>

        {{-- App filter tabs --}}
        <div class="mb-4 overflow-x-auto">
            <div class="ranking-tab-nav flex min-w-max items-center gap-1 rounded-xl bg-black/5 p-1 dark:bg-white/5">
                <button
                    wire:click="setAppFilter('all')"
                    class="shrink-0 rounded-lg px-3 py-1.5 text-sm font-medium transition-all {{ $appFilter === 'all' ? 'bg-white text-text-primary shadow-sm dark:bg-white/10 dark:text-white' : 'text-text-secondary hover:text-text-primary dark:text-text-tertiary dark:hover:text-white' }}"
                >
                    総合
                </button>
                @foreach ($this->apps as $app)
                    <button
                        wire:click="setAppFilter('{{ $app->api_slug }}')"
                        class="shrink-0 rounded-lg px-3 py-1.5 text-sm font-medium transition-all {{ $appFilter === $app->api_slug ? 'bg-white text-text-primary shadow-sm dark:bg-white/10 dark:text-white' : 'text-text-secondary hover:text-text-primary dark:text-text-tertiary dark:hover:text-white' }}"
                    >
                        {{ $app->name }}
                    </button>
                @endforeach
            </div>
        </div>

        {{-- Period selector --}}
        <div class="mb-5 flex items-center gap-2">
            <span class="text-xs font-medium text-text-secondary dark:text-text-tertiary">期間:</span>
            @foreach (['24h' => '24時間', 'weekly' => '週間', 'monthly' => '月間'] as $key => $label)
                <button
                    wire:click="setPeriod('{{ $key }}')"
                    class="rounded-full px-3 py-1 text-xs font-semibold transition-all {{ $period === $key ? 'bg-accent text-white' : 'border border-border/40 text-text-secondary hover:border-accent/40 hover:text-accent dark:border-border-dark/40 dark:text-text-tertiary dark:hover:text-accent' }}"
                >
                    {{ $label }}
                </button>
            @endforeach
        </div>

        {{-- Ranking table --}}
        <div class="overflow-hidden rounded-xl border border-border/40 bg-surface-elevated/60 backdrop-blur-xl dark:border-border-dark/40 dark:bg-surface-elevated-dark/60">
            {{-- Table header --}}
            <div class="grid grid-cols-[40px_1fr_64px_64px_80px] gap-2 border-b border-border/30 bg-black/5 px-3 py-2 text-[10px] font-bold uppercase tracking-wider text-text-secondary dark:border-border-dark/30 dark:bg-white/5 dark:text-text-tertiary">
                <div class="text-center">#</div>
                <div>サイト名</div>
                <div class="text-right">IN</div>
                <div class="text-right">OUT</div>
                <div class="text-right">スコア</div>
            </div>

            <ul class="divide-y divide-border/30 dark:divide-border-dark/30">
                @forelse ($this->rankedSites as $index => $site)
                    <li class="grid grid-cols-[40px_1fr_64px_64px_80px] items-center gap-2 px-3 py-2.5 transition-colors hover:bg-black/5 dark:hover:bg-white/5">
                        {{-- Rank badge --}}
                        <div class="flex justify-center">
                            @if ($index === 0)
                                <span class="flex size-7 items-center justify-center rounded-full bg-yellow-400 text-xs font-bold text-yellow-900">1</span>
                            @elseif ($index === 1)
                                <span class="flex size-7 items-center justify-center rounded-full bg-slate-300 text-xs font-bold text-slate-700">2</span>
                            @elseif ($index === 2)
                                <span class="flex size-7 items-center justify-center rounded-full bg-amber-600 text-xs font-bold text-white">3</span>
                            @else
                                <span class="flex size-7 items-center justify-center rounded-full text-xs font-medium text-text-secondary dark:text-text-tertiary">{{ $index + 1 }}</span>
                            @endif
                        </div>

                        {{-- Site name --}}
                        <div class="min-w-0">
                            <a href="{{ $site->url }}" target="_blank" rel="noopener noreferrer"
                               class="block truncate text-sm font-medium text-text-primary transition-colors hover:text-accent dark:text-white dark:hover:text-accent">
                                {{ $site->name }}
                            </a>
                        </div>

                        {{-- IN --}}
                        <div class="text-right">
                            <span class="text-xs font-semibold text-emerald-600 dark:text-emerald-400">
                                {{ number_format($site->daily_in_count ?? 0) }}
                            </span>
                        </div>

                        {{-- OUT --}}
                        <div class="text-right">
                            <span class="text-xs font-semibold text-sky-600 dark:text-sky-400">
                                {{ number_format($site->daily_out_count ?? 0) }}
                            </span>
                        </div>

                        {{-- Score --}}
                        <div class="text-right">
                            <span class="inline-flex items-center rounded-full bg-accent/10 px-2 py-0.5 text-[11px] font-bold text-accent dark:bg-accent/20">
                                {{ number_format($site->traffic_score ?? 0) }}
                            </span>
                        </div>
                    </li>
                @empty
                    <li class="p-10 text-center text-sm text-text-secondary dark:text-text-tertiary">
                        登録サイトがありません
                    </li>
                @endforelse
            </ul>
        </div>

        <p class="mt-3 text-[11px] text-text-secondary dark:text-text-tertiary">
            * IN: 当アンテナからサイトへのアクセス数 / OUT: サイトからの流入数 / スコア: 総合トラフィック指標
        </p>
    </div>
</div>
