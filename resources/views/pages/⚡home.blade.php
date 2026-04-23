<?php

use App\Models\App;
use App\Models\Article;
use App\Models\Category;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

new
#[Layout('layouts::app')]
class extends Component {
    /** App resolved from route model binding */
    public App $app;

    /** Currently selected category slug (null = all) */
    #[Url(as: 'cat')]
    public ?string $selectedCategory = null;

    /** Number of articles per page */
    public int $perPage = 20;

    /** Infeed ad interval (insert ad every N articles) */
    private const AD_INTERVAL = 10;

    public function selectCategory(?string $slug): void
    {
        $this->selectedCategory = $slug;
        $this->perPage = 20;
    }

    public function loadMore(): void
    {
        $this->perPage += 20;
    }

    /**
     * @return Collection<int, Category>
     */
    #[Computed]
    public function categories(): Collection
    {
        return $this->app->categories()
            ->select(['id', 'app_id', 'name', 'api_slug', 'sort_order'])
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
    }

    /**
     * @return Collection<int, Article>
     */
    #[Computed]
    public function articles(): Collection
    {
        $query = Article::query()
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
            ->join('sites', 'articles.site_id', '=', 'sites.id')
            ->where('articles.app_id', $this->app->id)
            ->with(['category:id,default_image_path', 'site:id,name,traffic_score'])
            ->trafficFiltered();

        if (filled($this->selectedCategory)) {
            $category = Category::query()
                ->where('app_id', $this->app->id)
                ->where('api_slug', $this->selectedCategory)
                ->first();

            if ($category) {
                $query->where('articles.category_id', $category->id);
            }
        }

        return $query
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
        $base = $this->app->name;

        if (filled($this->selectedCategory)) {
            $cat = $this->categories->firstWhere('api_slug', $this->selectedCategory);
            if ($cat) {
                return $cat->name . ' - ' . $base;
            }
        }

        return $base;
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
    @section('tenant_name', $this->app->name)

    {{-- Category tabs --}}
    <div class="sticky top-0 z-40 -mx-4 mb-2 bg-white/80 px-4 py-2 backdrop-blur-md dark:bg-surface-dark/80">
        <x-category-tabs
            :categories="$this->categories"
            :selected="$selectedCategory"
        />
    </div>

    {{-- Hot Entries --}}
    <x-hot-entries :targetApp="$this->app" />

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

            <x-article-card :article="$article" wire:key="article-{{ $article->id }}" />
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

    {{-- Loading state for category switch --}}
    <div wire:loading wire:target="selectCategory" class="fixed inset-0 z-50 flex items-center justify-center bg-black/10 backdrop-blur-[2px]">
        <div class="rounded-2xl bg-surface-elevated px-6 py-4 shadow-lg dark:bg-surface-elevated-dark">
            <svg class="mx-auto size-6 animate-spin text-accent" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
            </svg>
        </div>
    </div>
</div>
