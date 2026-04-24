<?php

use App\Models\App;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

new
#[Layout('layouts::app')]
class extends Component {
    /**
     * @return Collection<int, App>
     */
    #[Computed]
    public function appsWithSites(): Collection
    {
        return App::where('is_active', true)
            ->with([
                'sites' => function ($query) {
                    $query->where('is_active', true)
                        ->withCount('articles')
                        ->orderByDesc('traffic_score');
                },
                'categories' => function ($query) {
                    $query->orderBy('sort_order')->orderBy('id');
                },
            ])
            ->orderBy('id')
            ->get();
    }
}; ?>

<div x-data="{ copied: null }" @click.outside="copied = null">
    @section('title', '登録サイト一覧')
    @section('tenant_name', 'ゆにこーんアンテナ - 登録サイト一覧')

    <div class="mx-auto max-w-4xl">
        <div class="mb-6">
            <h1 class="text-2xl font-bold tracking-tight text-text-primary dark:text-white">🌐 登録サイト一覧</h1>
            <p class="mt-1 text-sm text-text-secondary dark:text-text-tertiary">
                当アンテナで記事を収集させていただいている登録サイトの一覧です。
                サイト名をクリックするとサイトへ、📋 をクリックするとRSSのURLをコピーできます。
            </p>
        </div>

        <div class="space-y-6">
            @foreach ($this->appsWithSites as $app)
                @if ($app->sites->isNotEmpty())
                    <section>
                        <h2 class="mb-3 flex items-center gap-2 text-base font-bold text-text-primary dark:text-white">
                            @if($app->icon_path)
                                <img src="{{ Storage::url($app->icon_path) }}" alt="" class="size-5 rounded">
                            @else
                                <span class="text-accent">📱</span>
                            @endif
                            {{ $app->name }}
                            <span class="ml-auto text-xs font-normal text-text-secondary dark:text-text-tertiary">
                                {{ $app->sites->count() }}サイト
                            </span>
                        </h2>

                        <div class="overflow-hidden rounded-xl border border-border/40 bg-surface-elevated/60 backdrop-blur-xl dark:border-border-dark/40 dark:bg-surface-elevated-dark/60">
                            {{-- Column header --}}
                            <div class="grid grid-cols-[1fr_auto_auto] gap-2 border-b border-border/30 bg-black/5 px-3 py-1.5 text-[10px] font-bold uppercase tracking-wider text-text-secondary dark:border-border-dark/30 dark:bg-white/5 dark:text-text-tertiary">
                                <div>サイト名</div>
                                <div class="text-right pr-8">記事数</div>
                                <div class="w-8 text-center">RSS</div>
                            </div>

                            <ul class="divide-y divide-border/30 dark:divide-border-dark/30">
                                @foreach ($app->sites as $site)
                                    @php
                                        $rssUrl = url('/s/' . $app->api_slug . '/rss');
                                    @endphp
                                    <li class="grid grid-cols-[1fr_auto_auto] items-center gap-2 px-3 py-2 transition-colors hover:bg-black/[0.03] dark:hover:bg-white/[0.03]">
                                        {{-- Site name --}}
                                        <div class="min-w-0">
                                            <a href="{{ $site->url }}"
                                               target="_blank" rel="noopener noreferrer"
                                               class="truncate text-sm font-medium text-text-primary transition-colors hover:text-accent dark:text-white dark:hover:text-accent">
                                                {{ $site->name }}
                                            </a>
                                            @if ($site->rss_url)
                                                <span class="ml-1.5 inline-block rounded bg-accent/10 px-1 py-0.5 text-[9px] font-bold uppercase text-accent">RSS</span>
                                            @endif
                                        </div>

                                        {{-- Article count --}}
                                        <div class="shrink-0 pr-2 text-right">
                                            <span class="text-xs text-text-secondary dark:text-text-tertiary">
                                                {{ number_format($site->articles_count) }}件
                                            </span>
                                        </div>

                                        {{-- RSS copy button --}}
                                        <div class="shrink-0">
                                            @if ($site->rss_url)
                                                <button
                                                    type="button"
                                                    x-data
                                                    @click.stop="
                                                        navigator.clipboard.writeText('{{ $site->rss_url }}');
                                                        $dispatch('copied', { id: {{ $site->id }} });
                                                        $root.copied = {{ $site->id }};
                                                        setTimeout(() => { if ($root.copied === {{ $site->id }}) $root.copied = null }, 2000);
                                                    "
                                                    class="flex size-7 items-center justify-center rounded-lg text-text-secondary transition-all hover:bg-accent/10 hover:text-accent dark:text-text-tertiary dark:hover:bg-accent/20 dark:hover:text-accent"
                                                    title="RSSのURLをコピー"
                                                >
                                                    <span x-show="$root.copied !== {{ $site->id }}" class="text-sm">📋</span>
                                                    <span x-show="$root.copied === {{ $site->id }}" x-cloak class="text-sm">✅</span>
                                                </button>
                                            @endif
                                        </div>
                                    </li>
                                @endforeach
                            </ul>
                        </div>

                        {{-- Category RSS select --}}
                        @if ($app->categories->isNotEmpty())
                            <div class="mt-2 flex items-center gap-2">
                                <span class="text-xs text-text-secondary dark:text-text-tertiary">カテゴリRSS:</span>
                                <select
                                    class="rounded-lg border border-border/40 bg-surface-elevated px-3 py-1.5 text-xs text-text-primary transition-colors focus:border-accent focus:outline-none dark:border-border-dark/40 dark:bg-surface-elevated-dark dark:text-white"
                                    onchange="if(this.value) window.open(this.value, '_blank'); this.value='';"
                                >
                                    <option value="">カテゴリを選択…</option>
                                    @foreach ($app->categories as $category)
                                        <option value="{{ url('/s/' . $app->api_slug . '/c/' . $category->api_slug . '/rss') }}">
                                            {{ $category->name }}
                                        </option>
                                    @endforeach
                                </select>
                                <span class="text-[10px] text-text-secondary dark:text-text-tertiary">（選択するとRSSページへ）</span>
                            </div>
                        @endif
                    </section>
                @endif
            @endforeach
        </div>
    </div>
</div>
