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

<div
    x-data="{
        copied: null,
        copiedTimer: null,
        async copyText(text, key) {
            try {
                await navigator.clipboard.writeText(text);
                this.copied = key;
                if (this.copiedTimer) {
                    window.clearTimeout(this.copiedTimer);
                }
                this.copiedTimer = window.setTimeout(() => {
                    if (this.copied === key) {
                        this.copied = null;
                    }
                }, 1500);
            } catch (error) {
                console.error(error);
            }
        },
    }"
>
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
                <h2 class="mb-2 mt-6 px-4 flex items-center gap-2 text-sm font-semibold uppercase tracking-wider text-text-secondary dark:text-text-tertiary">
                    <span class="text-accent">🌐</span> 総合RSS
                </h2>
                <div class="overflow-hidden rounded-2xl bg-surface-elevated dark:bg-surface-elevated-dark shadow-sm">
                    @php $crossRssUrl = url('/rss'); @endphp
                    <button
                        type="button"
                        @click="copyText(@js($crossRssUrl), 'cross')"
                        class="w-full flex items-center justify-between px-4 py-3 min-h-[44px] transition-colors hover:bg-black/5 dark:hover:bg-white/5 text-left"
                    >
                        <span class="truncate text-sm font-medium text-text-primary dark:text-white">ゆにこーんアンテナ 総合RSS</span>
                        <div class="shrink-0 pl-4">
                            <span x-show="copied !== 'cross'" class="text-xs text-text-secondary dark:text-text-tertiary transition-opacity duration-150">コピー</span>
                            <span x-show="copied === 'cross'" x-cloak class="inline-flex items-center gap-1 text-xs font-semibold text-accent transition-opacity duration-150">
                                <span>✅</span> Copied!
                            </span>
                        </div>
                    </button>
                </div>
            </section>

            {{-- アプリごとのRSS --}}
            @foreach ($this->apps as $app)
                <section>
                    <h2 class="mb-2 mt-6 px-4 flex items-center gap-2 text-sm font-semibold uppercase tracking-wider text-text-secondary dark:text-text-tertiary">
                        @if($app->icon_path)
                            <img src="{{ Storage::url($app->icon_path) }}" alt="" class="size-4 rounded">
                        @endif
                        {{ $app->name }}
                    </h2>

                    <div class="overflow-hidden rounded-2xl bg-surface-elevated dark:bg-surface-elevated-dark shadow-sm">
                        <ul class="divide-y divide-border/40 dark:divide-border-dark/40">
                            @php $appRssUrl = url('/s/' . $app->api_slug . '/rss'); @endphp

                            {{-- App-wide RSS row --}}
                            <li>
                                <button
                                    type="button"
                                    @click="copyText(@js($appRssUrl), 'app-{{ $app->id }}')"
                                    class="w-full flex items-center justify-between px-4 py-3 min-h-[44px] transition-colors hover:bg-black/5 dark:hover:bg-white/5 text-left"
                                >
                                    <span class="truncate text-sm font-bold text-text-primary dark:text-white">アプリ全体</span>
                                    <div class="shrink-0 pl-4">
                                        <span x-show="copied !== 'app-{{ $app->id }}'" class="text-xs text-text-secondary dark:text-text-tertiary transition-opacity duration-150">コピー</span>
                                        <span x-show="copied === 'app-{{ $app->id }}'" x-cloak class="inline-flex items-center gap-1 text-xs font-semibold text-accent transition-opacity duration-150">
                                            <span>✅</span> Copied!
                                        </span>
                                    </div>
                                </button>
                            </li>

                            @if ($app->categories->isNotEmpty())
                                @foreach ($app->categories as $category)
                                    @php
                                        $catRssUrl = url('/s/' . $app->api_slug . '/c/' . $category->api_slug . '/rss');
                                        $copyKey = 'cat-' . $category->id;
                                    @endphp
                                    <li>
                                        <button
                                            type="button"
                                            @click="copyText(@js($catRssUrl), '{{ $copyKey }}')"
                                            class="w-full flex items-center justify-between px-4 py-3 min-h-[44px] transition-colors hover:bg-black/5 dark:hover:bg-white/5 text-left"
                                        >
                                            <span class="truncate text-sm font-medium text-text-primary dark:text-white">{{ $category->name }}</span>
                                            <div class="shrink-0 pl-4">
                                                <span x-show="copied !== '{{ $copyKey }}'" class="text-xs text-text-secondary dark:text-text-tertiary transition-opacity duration-150">コピー</span>
                                                <span x-show="copied === '{{ $copyKey }}'" x-cloak class="inline-flex items-center gap-1 text-xs font-semibold text-accent transition-opacity duration-150">
                                                    <span>✅</span> Copied!
                                                </span>
                                            </div>
                                        </button>
                                    </li>
                                @endforeach
                            @endif
                        </ul>
                    </div>
                </section>
            @endforeach
        </div>
    </div>
</div>
