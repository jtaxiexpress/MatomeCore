<?php

use App\Models\Site;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

new
#[Layout('layouts::app')]
class extends Component {
    #[Computed]
    public function rankedSites(): Collection
    {
        return Site::query()
            ->where('is_active', true)
            ->orderByDesc('traffic_score')
            ->orderByDesc('id')
            ->get();
    }
}; ?>

<div>
    @section('title', '登録サイトランキング')
    @section('tenant_name', 'ゆにこーんアンテナ - 登録サイトランキング')

    <div class="mx-auto max-w-3xl">
        <div class="mb-8 text-center">
            <h1 class="text-2xl font-bold tracking-tight text-text-primary dark:text-white">登録サイトランキング</h1>
            <p class="mt-2 text-sm text-text-secondary dark:text-text-tertiary">
                当アンテナにご登録いただいているサイト様のアクセスランキングです。<br>
                （※トラフィックスコア順）
            </p>
        </div>

        <div class="rounded-2xl bg-surface-elevated shadow-sm dark:bg-surface-elevated-dark overflow-hidden border border-border/40 dark:border-border-dark/40">
            <ul class="divide-y divide-border/40 dark:divide-border-dark/40">
                @forelse ($this->rankedSites as $index => $site)
                    <li class="flex items-center gap-4 p-4 transition-colors hover:bg-black/5 dark:hover:bg-white/5">
                        <div class="flex size-10 shrink-0 items-center justify-center rounded-full font-bold {{ $index === 0 ? 'bg-yellow-500 text-white' : ($index === 1 ? 'bg-gray-400 text-white' : ($index === 2 ? 'bg-amber-600 text-white' : 'bg-surface dark:bg-surface-dark text-text-secondary dark:text-text-tertiary')) }}">
                            {{ $index + 1 }}
                        </div>
                        <div class="min-w-0 flex-1">
                            <a href="{{ $site->url }}" target="_blank" rel="noopener noreferrer" class="truncate text-base font-medium text-text-primary hover:text-accent dark:text-white dark:hover:text-accent">
                                {{ $site->name }}
                            </a>
                        </div>
                        <div class="shrink-0 text-right">
                            <span class="inline-flex items-center gap-1 rounded-full bg-accent/10 px-2.5 py-0.5 text-xs font-semibold text-accent dark:bg-accent/20">
                                📈 {{ number_format($site->traffic_score ?? 0) }} pts
                            </span>
                        </div>
                    </li>
                @empty
                    <li class="p-8 text-center text-sm text-text-secondary dark:text-text-tertiary">
                        登録サイトがありません
                    </li>
                @endforelse
            </ul>
        </div>
    </div>
</div>
