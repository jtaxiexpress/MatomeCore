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
    public function apps(): Collection
    {
        return App::where('is_active', true)
            ->with(['categories' => function ($query) {
                $query->orderBy('sort_order')->orderBy('id');
            }])
            ->orderBy('id')
            ->get();
    }
}; ?>

<div x-data="{ copied: null }">
    @section('title', 'RSS配信一覧')
    @section('tenant_name', config('app.name'))

    <div class="mx-auto max-w-3xl">
        <div class="mb-6">
            <h1 class="text-2xl font-bold tracking-tight text-text-primary dark:text-white">📡 RSS配信一覧</h1>
            <p class="mt-1 text-sm text-text-secondary dark:text-text-tertiary">
                各カテゴリやアプリごとの新着記事をRSS形式で配信しています。
                📋 をクリックするとURLをコピー、サイト名をクリックするとRSSフィードへ遷移します。
            </p>
        </div>

        <div class="space-y-6">
            {{-- 総合RSS --}}
            <section>
                <h2 class="mb-3 flex items-center gap-2 text-base font-bold text-text-primary dark:text-white">
                    <span class="text-accent">🌐</span> 総合RSS
                </h2>
                <div class="overflow-hidden rounded-xl border border-border/40 bg-surface-elevated/60 backdrop-blur-xl dark:border-border-dark/40 dark:bg-surface-elevated-dark/60">
                    @php $crossRssUrl = url('/rss'); @endphp
                    <div class="flex items-center gap-2 px-4 py-3">
                        <a href="{{ $crossRssUrl }}"
                           target="_blank" rel="noopener noreferrer"
                           class="flex-1 truncate text-sm font-medium text-accent hover:underline">
                            {{ $crossRssUrl }}
                        </a>
                        <button
                            type="button"
                            @click="navigator.clipboard.writeText('{{ $crossRssUrl }}'); copied = 'cross'; setTimeout(() => { if (copied === 'cross') copied = null }, 2000);"
                            class="flex size-7 shrink-0 items-center justify-center rounded-lg transition-all hover:bg-accent/10"
                            title="URLをコピー"
                        >
                            <span x-show="copied !== 'cross'">📋</span>
                            <span x-show="copied === 'cross'" x-cloak>✅</span>
                        </button>
                    </div>
                </div>
            </section>

            {{-- アプリごとのRSS --}}
            @foreach ($this->apps as $app)
                <section>
                    <h2 class="mb-3 flex items-center gap-2 text-base font-bold text-text-primary dark:text-white">
                        @if($app->icon_path)
                            <img src="{{ Storage::url($app->icon_path) }}" alt="" class="size-5 rounded">
                        @else
                            <span class="text-accent">📱</span>
                        @endif
                        {{ $app->name }}
                    </h2>

                    <div class="overflow-hidden rounded-xl border border-border/40 bg-surface-elevated/60 backdrop-blur-xl dark:border-border-dark/40 dark:bg-surface-elevated-dark/60">
                        @php $appRssUrl = url('/s/' . $app->api_slug . '/rss'); @endphp

                        {{-- App-wide RSS row --}}
                        <div class="flex items-center gap-2 border-b border-border/30 px-4 py-3 dark:border-border-dark/30">
                            <div class="min-w-0 flex-1">
                                <span class="text-[10px] font-bold uppercase tracking-wider text-text-secondary dark:text-text-tertiary">アプリ全体</span>
                                <a href="{{ $appRssUrl }}"
                                   target="_blank" rel="noopener noreferrer"
                                   class="block truncate text-sm font-medium text-accent hover:underline">
                                    {{ $appRssUrl }}
                                </a>
                            </div>
                            <button
                                type="button"
                                @click="navigator.clipboard.writeText('{{ $appRssUrl }}'); copied = 'app-{{ $app->id }}'; setTimeout(() => { if (copied === 'app-{{ $app->id }}') copied = null }, 2000);"
                                class="flex size-7 shrink-0 items-center justify-center rounded-lg transition-all hover:bg-accent/10"
                                title="URLをコピー"
                            >
                                <span x-show="copied !== 'app-{{ $app->id }}'">📋</span>
                                <span x-show="copied === 'app-{{ $app->id }}'" x-cloak>✅</span>
                            </button>
                        </div>

                        @if ($app->categories->isNotEmpty())
                            {{-- Category RSS -- list each + select dropdown --}}
                            <div class="divide-y divide-border/20 dark:divide-border-dark/20">
                                @foreach ($app->categories as $category)
                                    @php
                                        $catRssUrl = url('/s/' . $app->api_slug . '/c/' . $category->api_slug . '/rss');
                                        $copyKey = 'cat-' . $category->id;
                                    @endphp
                                    <div class="flex items-center gap-2 px-4 py-2.5">
                                        <div class="min-w-0 flex-1">
                                            <span class="mr-2 text-xs font-medium text-text-secondary dark:text-text-tertiary">{{ $category->name }}</span>
                                            <a href="{{ $catRssUrl }}"
                                               target="_blank" rel="noopener noreferrer"
                                               class="truncate text-xs text-accent hover:underline">
                                                {{ $catRssUrl }}
                                            </a>
                                        </div>
                                        <button
                                            type="button"
                                            @click="navigator.clipboard.writeText('{{ $catRssUrl }}'); copied = '{{ $copyKey }}'; setTimeout(() => { if (copied === '{{ $copyKey }}') copied = null }, 2000);"
                                            class="flex size-6 shrink-0 items-center justify-center rounded-lg text-sm transition-all hover:bg-accent/10"
                                            title="URLをコピー"
                                        >
                                            <span x-show="copied !== '{{ $copyKey }}'">📋</span>
                                            <span x-show="copied === '{{ $copyKey }}'" x-cloak>✅</span>
                                        </button>
                                    </div>
                                @endforeach
                            </div>

                            {{-- Category select for quick jump --}}
                            <div class="border-t border-border/20 px-4 py-2.5 dark:border-border-dark/20">
                                <div class="flex items-center gap-2">
                                    <span class="text-xs text-text-secondary dark:text-text-tertiary">カテゴリに移動:</span>
                                    <select
                                        class="flex-1 rounded-lg border border-border/40 bg-surface px-3 py-1.5 text-xs text-text-primary focus:border-accent focus:outline-none dark:border-border-dark/40 dark:bg-surface-dark dark:text-white"
                                        onchange="if(this.value) { window.location.href = this.value; } this.value='';"
                                    >
                                        <option value="">カテゴリを選択…</option>
                                        @foreach ($app->categories as $category)
                                            <option value="{{ url('/s/' . $app->api_slug . '/c/' . $category->api_slug . '/rss') }}">
                                                {{ $category->name }}
                                            </option>
                                        @endforeach
                                    </select>
                                </div>
                            </div>
                        @endif
                    </div>
                </section>
            @endforeach
        </div>
    </div>
</div>
