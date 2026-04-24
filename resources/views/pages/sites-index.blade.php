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
    @click.outside="copied = null"
>
    @section('title', '登録サイト一覧')
    @section('tenant_name', config('app.name'))

    <div class="mx-auto max-w-4xl">
        <div class="mb-6">
            <h1 class="text-2xl font-bold tracking-tight text-text-primary dark:text-white">🌐 登録サイト一覧</h1>
        </div>

        <div class="space-y-6">
            @foreach ($this->appsWithSites as $app)
                @if ($app->sites->isNotEmpty())
                    <section>
                        <h2 class="mb-3 flex items-center gap-2 text-base font-bold text-text-primary dark:text-white">
                            @if($app->icon_path)
                                <img src="{{ Storage::url($app->icon_path) }}" alt="" class="size-5 rounded">
                            @endif
                            {{ $app->name }}
                            <span class="ml-auto text-xs font-normal text-text-secondary dark:text-text-tertiary">
                                {{ $app->sites->count() }}サイト
                            </span>
                        </h2>

                        <div class="overflow-hidden rounded-xl border border-border/40 bg-surface-elevated/60 backdrop-blur-xl dark:border-border-dark/40 dark:bg-surface-elevated-dark/60">
                            {{-- Column header --}}
                            <div class="grid grid-cols-[1fr_auto] gap-2 border-b border-border/30 bg-black/5 px-3 py-1.5 text-[10px] font-bold uppercase tracking-wider text-text-secondary dark:border-border-dark/30 dark:bg-white/5 dark:text-text-tertiary">
                                <div>サイト名</div>
                                <div class="text-right pr-8">記事数</div>
                            </div>

                            <ul class="divide-y divide-border/30 dark:divide-border-dark/30">
                                @foreach ($app->sites as $site)
                                    <li class="grid grid-cols-[1fr_auto] items-center gap-2 px-3 py-2 transition-colors hover:bg-black/[0.03] dark:hover:bg-white/[0.03]">
                                        {{-- Site name --}}
                                        <div class="min-w-0">
                                            <a href="{{ $site->url }}"
                                               target="_blank" rel="noopener noreferrer"
                                               class="truncate text-sm font-medium text-text-primary transition-colors hover:text-accent dark:text-white dark:hover:text-accent">
                                                {{ $site->name }}
                                            </a>
                                        </div>

                                        {{-- Article count --}}
                                        <div class="shrink-0 pr-2 text-right">
                                            <span class="text-xs text-text-secondary dark:text-text-tertiary">
                                                {{ number_format($site->articles_count) }}件
                                            </span>
                                        </div>
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    </section>
                @endif
            @endforeach
        </div>
    </div>
</div>
