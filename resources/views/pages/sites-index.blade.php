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
                        <h2 class="mb-2 mt-6 flex items-center gap-2 px-4 text-sm font-semibold uppercase tracking-wider text-text-secondary dark:text-text-tertiary">
                            @if($app->icon_path)
                                <img src="{{ Storage::url($app->icon_path) }}" alt="" class="size-4 rounded">
                            @endif
                            {{ $app->name }}
                            <span class="ml-auto text-xs font-normal">
                                {{ $app->sites->count() }}サイト
                            </span>
                        </h2>

                        <div class="overflow-hidden rounded-2xl bg-surface-elevated dark:bg-surface-elevated-dark shadow-sm">
                            <ul class="divide-y divide-border/40 dark:divide-border-dark/40">
                                @foreach ($app->sites as $site)
                                    <li class="flex items-center justify-between px-4 py-3 min-h-[44px] transition-colors hover:bg-black/5 dark:hover:bg-white/5">
                                        {{-- Site name --}}
                                        <div class="min-w-0 flex-1 pr-4">
                                            <a href="{{ $site->url }}"
                                               target="_blank" rel="noopener noreferrer"
                                               class="block truncate text-sm font-medium text-text-primary transition-colors hover:text-accent dark:text-white dark:hover:text-accent">
                                                {{ $site->name }}
                                            </a>
                                        </div>

                                        {{-- Article count --}}
                                        <div class="shrink-0 text-right">
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
