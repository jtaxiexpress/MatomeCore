<?php

use App\Models\App;
use App\Models\Article;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

use Livewire\WithPagination;

new
#[Layout('layouts::app')]
class extends Component {
    use WithPagination;

    /** Infeed ad interval (insert ad every N articles) */
    private const AD_INTERVAL = 10;

    /**
     * @return \Illuminate\Contracts\Pagination\LengthAwarePaginator
     */
    #[Computed]
    public function articles(): \Illuminate\Contracts\Pagination\LengthAwarePaginator
    {
        // active_app_ids は整数の配列なので安全にキャッシュ可
        $activeAppIds = Cache::remember('active_app_ids', now()->addMinutes(60), function () {
            return App::where('is_active', true)->pluck('id')->toArray();
        });

        // Paginator は Eloquent モデルを含むため、シリアライズ不可 → キャッシュしない
        return Article::query()
            ->select([
                'articles.id',
                'articles.app_id',
                'articles.category_id',
                'articles.site_id',
                'articles.title',
                'articles.url',
                'articles.thumbnail_url',
                'articles.published_at',
                'articles.daily_out_count',
            ])
            ->whereIn('articles.app_id', $activeAppIds)
            ->with(['app:id,name,api_slug', 'site:id,name,traffic_score'])
            ->trafficFiltered()
            ->join('sites', 'articles.site_id', '=', 'sites.id')
            ->orderByDesc('sites.traffic_score')
            ->orderByDesc('articles.published_at')
            ->orderByDesc('articles.id')
            ->paginate(30);
    }

    #[Computed]
    public function appSections(): Collection
    {
        // Eloquent モデルを含むコレクションはシリアライズ不可 → キャッシュしない
        return App::where('is_active', true)
            ->get()
            ->map(function ($app) {
                $app->setRelation('latest_articles', Article::query()
                    ->select([
                        'articles.id',
                        'articles.app_id',
                        'articles.category_id',
                        'articles.site_id',
                        'articles.title',
                        'articles.url',
                        'articles.thumbnail_url',
                        'articles.published_at',
                        'articles.daily_out_count',
                    ])
                    ->where('articles.app_id', $app->id)
                    ->with(['app:id,name,api_slug', 'site:id,name,traffic_score'])
                    ->trafficFiltered()
                    ->join('sites', 'articles.site_id', '=', 'sites.id')
                    ->orderByDesc('sites.traffic_score')
                    ->orderByDesc('articles.published_at')
                    ->orderByDesc('articles.id')
                    ->limit(10)
                    ->get()
                );
                return $app;
            });
    }

    #[Computed]
    public function adInterval(): int
    {
        return self::AD_INTERVAL;
    }

    #[Computed]
    public function pageTitle(): string
    {
        return 'ゆにこーんアンテナ - 横断アンテナ';
    }
}; ?>

<div x-data="{ 
    mutedSites: JSON.parse(localStorage.getItem('muted_sites') || '[]'),
    muteSite(id) {
        if (!this.mutedSites.includes(id)) {
            this.mutedSites.push(id);
            localStorage.setItem('muted_sites', JSON.stringify(this.mutedSites));
        }
    }
}">
    @section('title', $this->pageTitle)
    @section('tenant_name', 'ゆにこーんアンテナ 全体記事')

    {{-- Hot Entries --}}
    <x-hot-entries />

    {{-- Comprehensive Article feed --}}
    <section class="mb-12">
        <h2 class="mb-4 flex items-center gap-2 text-xl font-bold text-text-primary dark:text-white">
            <span class="text-accent">🌟</span> 全アプリ総合最新記事
        </h2>
        
        <div class="flex flex-col gap-0" id="article-feed">
            @php
                $lastDate = null;
            @endphp
            @forelse ($this->articles as $index => $article)
                @php
                    $currentDate = $article->published_at ? $article->published_at->translatedFormat('n月j日（D）') : '未設定';
                @endphp

                @if ($lastDate !== $currentDate)
                    <div class="mt-3 mb-1 flex items-center gap-2 first:mt-0">
                        <span class="text-sm font-bold text-text-primary dark:text-white">{{ $currentDate }}</span>
                        <div class="h-px flex-1 bg-border/50 dark:bg-border-dark/50"></div>
                    </div>
                    @php
                        $lastDate = $currentDate;
                    @endphp
                @endif

                {{-- Infeed ad every N articles --}}
                @if ($index > 0 && $index % $this->adInterval === 0)
                    <div class="py-2">
                        <x-ad-infeed />
                    </div>
                @endif

                <x-article-card :article="$article" wire:key="article-cross-{{ $article->id }}" />
            @empty
                <div class="flex flex-col items-center justify-center rounded-xl bg-surface-elevated px-4 py-16 text-center dark:bg-surface-elevated-dark border border-border/40 dark:border-border-dark/40">
                    <span class="mb-2 text-4xl">📭</span>
                    <p class="text-sm font-medium text-text-secondary dark:text-text-tertiary">
                        記事が見つかりませんでした
                    </p>
                </div>
            @endforelse
        </div>

        {{-- Pagination --}}
        <div class="mt-6 mb-8 px-4">
            {{ $this->articles->links(data: ['scrollTo' => false]) }}
        </div>
    </section>

    {{-- App Sections --}}
    <div class="space-y-10">
        @foreach($this->appSections as $app)
            @if($app->latest_articles->isNotEmpty())
                <section>
                    {{-- Section header --}}
                    <div class="mb-3 flex items-center justify-between border-b border-border/30 pb-2 dark:border-border-dark/30">
                        <h2 class="flex items-center gap-2 text-base font-bold text-text-primary dark:text-white">
                            @if($app->icon_path)
                                <img src="{{ Storage::url($app->icon_path) }}" alt="" class="size-5 rounded">
                            @else
                                <span class="text-accent">📱</span>
                            @endif
                            {{ $app->name }}
                            <span class="ml-1 text-xs font-normal text-text-secondary dark:text-text-tertiary">最新</span>
                        </h2>
                        <a href="{{ route('front.home', $app) }}"
                           class="flex items-center gap-1 text-xs font-medium text-accent transition-colors hover:text-accent/70"
                           wire:navigate>
                            もっと見る
                            <svg class="size-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                        </a>
                    </div>

                    <div class="flex flex-col gap-0">
                        @foreach($app->latest_articles as $article)
                            <x-article-card :article="$article" wire:key="app-{{ $app->id }}-article-{{ $article->id }}" />
                        @endforeach
                    </div>
                </section>
            @endif
        @endforeach
    </div>
</div>
