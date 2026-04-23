<?php

use App\Models\App;
use App\Models\Article;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

new
#[Layout('layouts::app')]
class extends Component {
    /** Number of articles per page */
    public int $perPage = 20;

    /** Infeed ad interval (insert ad every N articles) */
    private const AD_INTERVAL = 10;

    public function loadMore(): void
    {
        $this->perPage += 20;
    }

    /**
     * @return Collection<int, Article>
     */
    #[Computed]
    public function articles(): Collection
    {
        // 1. Get active app IDs
        $activeAppIds = App::where('is_active', true)->pluck('id');

        // 2. Fetch cross-app articles
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
            ->limit($this->perPage)
            ->get();
    }

    #[Computed]
    public function hasMoreArticles(): bool
    {
        return $this->articles->count() >= $this->perPage;
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

    {{-- Article feed --}}
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
            <div class="flex flex-col items-center justify-center rounded-xl bg-surface-elevated px-4 py-16 text-center dark:bg-surface-elevated-dark">
                <span class="mb-2 text-4xl">📭</span>
                <p class="text-sm font-medium text-text-secondary dark:text-text-tertiary">
                    記事が見つかりませんでした
                </p>
            </div>
        @endforelse
    </div>

    {{-- Load more --}}
    @if ($this->hasMoreArticles)
        <div class="mt-4 flex justify-center pb-8">
            <button
                type="button"
                wire:click="loadMore"
                class="rounded-full bg-black/5 px-6 py-2.5 text-sm font-medium text-text-secondary transition-all duration-200 hover:bg-black/10 active:scale-95 dark:bg-white/10 dark:text-text-tertiary dark:hover:bg-white/15"
                id="load-more-button"
            >
                <span wire:loading.remove wire:target="loadMore">もっと見る</span>
                <span wire:loading wire:target="loadMore" class="inline-flex items-center gap-2">
                    <svg class="size-4 animate-spin" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                    </svg>
                    読み込み中...
                </span>
            </button>
        </div>
    @endif
</div>
