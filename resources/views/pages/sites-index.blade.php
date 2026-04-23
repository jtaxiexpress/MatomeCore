<?php

use App\Models\App;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

new
#[Layout('layouts::app')]
class extends Component {
    #[Computed]
    public function appsWithSites(): Collection
    {
        return App::where('is_active', true)
            ->with(['sites' => function ($query) {
                $query->where('is_active', true)->withCount('articles');
            }])
            ->orderBy('id')
            ->get();
    }
}; ?>

<div>
    @section('title', '登録サイト一覧')
    @section('tenant_name', 'ゆにこーんアンテナ - 登録サイト一覧')

    <div class="mx-auto max-w-4xl space-y-8">
        <div class="text-center">
            <h1 class="text-2xl font-bold tracking-tight text-text-primary dark:text-white">登録サイト一覧</h1>
            <p class="mt-2 text-sm text-text-secondary dark:text-text-tertiary">
                当アンテナで記事を収集させていただいている登録サイトの一覧です。
            </p>
        </div>

        <div class="space-y-8">
            @foreach ($this->appsWithSites as $app)
                @if ($app->sites->isNotEmpty())
                    <section>
                        <h2 class="mb-4 flex items-center gap-2 text-xl font-bold text-text-primary dark:text-white">
                            @if($app->icon_path)
                                <img src="{{ Storage::url($app->icon_path) }}" alt="" class="size-6 rounded">
                            @else
                                <span class="text-accent">📱</span>
                            @endif
                            {{ $app->name }}
                        </h2>

                        <div class="rounded-xl border border-border/40 bg-surface-elevated/50 backdrop-blur-xl dark:border-border-dark/40 dark:bg-surface-elevated-dark/50 overflow-hidden">
                            <ul class="divide-y divide-border/40 dark:divide-border-dark/40">
                                @foreach ($app->sites as $site)
                                    <li class="flex items-center justify-between gap-2 p-3 hover:bg-black/5 dark:hover:bg-white/5 transition-colors">
                                        <div class="min-w-0 flex-1">
                                            <a href="{{ $site->url }}" target="_blank" rel="noopener noreferrer" class="truncate text-sm font-medium text-text-primary hover:text-accent dark:text-white dark:hover:text-accent">
                                                {{ $site->name }}
                                            </a>
                                            @if ($site->contact_notes)
                                                <span class="ml-2 text-[11px] text-text-secondary dark:text-text-tertiary truncate max-w-[150px] sm:max-w-[300px] inline-block align-middle hidden sm:inline-block">
                                                    - {{ $site->contact_notes }}
                                                </span>
                                            @endif
                                        </div>
                                        <div class="shrink-0 text-right">
                                            <span class="inline-flex items-center justify-center rounded-full bg-accent/10 px-2 py-0.5 text-[10px] font-semibold text-accent dark:bg-accent/20">
                                                記事: {{ number_format($site->articles_count) }}
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
