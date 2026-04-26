<?php

use App\Models\App;
use App\Models\Article;
use App\Models\Category;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

use Livewire\WithPagination;

new
#[Layout('layouts::app')]
class extends Component {
    use WithPagination;

    /** App resolved from route model binding */
    public App $app;

    /** Currently selected category slug (null = all) */
    #[Url(as: 'cat')]
    public ?string $selectedCategory = null;

    /** Infeed ad interval (insert ad every N articles) */
    private const AD_INTERVAL = 10;

    public function selectCategory(?string $slug): void
    {
        $this->selectedCategory = $slug;
        $this->resetPage();
    }

    /**
     * @return Collection<int, Category>
     */
    #[Computed]
    public function categories(): Collection
    {
        // Eloquent モデルを含むコレクションはシリアライズ不可 → キャッシュしない
        return $this->app->categories()
            ->select(['id', 'app_id', 'name', 'api_slug', 'sort_order'])
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
    }

    /**
     * @return \Illuminate\Contracts\Pagination\LengthAwarePaginator
     */
    #[Computed]
    public function articles(): \Illuminate\Contracts\Pagination\LengthAwarePaginator
    {
        // Paginator は Eloquent モデルを含むため、シリアライズ不可 → キャッシュしない
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
            ->paginate(50);
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
    <div class="sticky top-14 z-40 mb-4 overflow-hidden rounded-2xl border border-border/40 bg-surface-elevated/80 px-0 py-2 shadow-sm backdrop-blur-md dark:border-border-dark/40 dark:bg-surface-elevated-dark/80">
        <x-category-tabs
            :categories="$this->categories"
            :selected="$selectedCategory"
        />
    </div>

    <div
        wire:loading.delay.short
        wire:target="selectCategory"
        class="mb-4 rounded-2xl border border-border/40 bg-surface-elevated/80 p-4 shadow-sm backdrop-blur-xl dark:border-border-dark/40 dark:bg-surface-elevated-dark/80"
    >
        <div class="animate-pulse space-y-3">
            <div class="flex items-center gap-3">
                <div class="size-10 rounded-xl bg-black/5 dark:bg-white/10"></div>
                <div class="flex-1 space-y-2">
                    <div class="h-3 w-28 rounded-full bg-black/5 dark:bg-white/10"></div>
                    <div class="h-2 w-full rounded-full bg-black/5 dark:bg-white/10"></div>
                </div>
            </div>

            <div class="space-y-2">
                @for ($i = 0; $i < 5; $i++)
                    <div class="flex items-center gap-3 rounded-xl border border-border/20 px-3 py-2 dark:border-border-dark/20">
                        <div class="size-8 rounded-full bg-black/5 dark:bg-white/10"></div>
                        <div class="flex-1 space-y-2">
                            <div class="h-3 w-2/3 rounded-full bg-black/5 dark:bg-white/10"></div>
                            <div class="h-2 w-1/3 rounded-full bg-black/5 dark:bg-white/10"></div>
                        </div>
                        <div class="size-10 rounded-full bg-black/5 dark:bg-white/10"></div>
                    </div>
                @endfor
            </div>
        </div>
    </div>

    {{-- Article feed --}}
    <div
        wire:loading.class="opacity-60"
        wire:loading.class="pointer-events-none"
        wire:target="selectCategory"
        class="space-y-6 transition-opacity duration-200"
        id="article-feed"
    >
        @php
            $lastDate = null;
            $isFirst = true;
        @endphp
        @forelse ($this->articles as $index => $article)
            @php
                $currentDate = $article->published_at ? $article->published_at->translatedFormat('n月j日（D）') : '未設定';
            @endphp

            @if ($lastDate !== $currentDate)
                @if (!$isFirst)
                        </div>
                    </div>
                @endif
                <div class="date-group">
                    <h3 class="mb-2 px-4 flex items-center gap-2 text-sm font-semibold uppercase tracking-wider text-text-secondary dark:text-text-tertiary">
                        {{ $currentDate }}
                    </h3>
                    <div class="overflow-hidden rounded-2xl bg-surface-elevated dark:bg-surface-elevated-dark shadow-sm divide-y divide-border/40 dark:divide-border-dark/40">
                @php
                    $lastDate = $currentDate;
                    $isFirst = false;
                @endphp
            @endif

            {{-- Infeed ad every N articles --}}
            @if ($index > 0 && $index % $this->adInterval === 0)
                <div class="py-3 px-4 bg-transparent">
                    <x-ad-infeed />
                </div>
            @endif

            <x-article-card :article="$article" wire:key="article-{{ $article->id }}" />
        @empty
            <div class="flex flex-col items-center justify-center rounded-2xl bg-surface-elevated px-4 py-16 text-center dark:bg-surface-elevated-dark shadow-sm">
                <span class="mb-2 text-4xl">📭</span>
                <p class="text-sm font-medium text-text-secondary dark:text-text-tertiary">
                    記事が見つかりませんでした
                </p>
            </div>
        @endforelse

        @if (!$isFirst)
                </div>
            </div>
        @endif
    </div>

    {{-- Pagination --}}
    <div class="mt-6 mb-8 px-4">
        {{ $this->articles->links(data: ['scrollTo' => false]) }}
    </div>
</div>
