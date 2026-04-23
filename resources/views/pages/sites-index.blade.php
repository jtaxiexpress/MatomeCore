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

                        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                            @foreach ($app->sites as $site)
                                <div class="flex flex-col justify-between rounded-xl border border-border/40 bg-surface-elevated p-4 shadow-sm dark:border-border-dark/40 dark:bg-surface-elevated-dark">
                                    <div>
                                        <a href="{{ $site->url }}" target="_blank" rel="noopener noreferrer" class="text-sm font-bold text-text-primary hover:text-accent dark:text-white dark:hover:text-accent line-clamp-2">
                                            {{ $site->name }}
                                        </a>
                                        @if ($site->contact_notes)
                                            <p class="mt-2 text-xs text-text-secondary dark:text-text-tertiary line-clamp-2">
                                                {{ $site->contact_notes }}
                                            </p>
                                        @endif
                                    </div>
                                    <div class="mt-4 flex items-center justify-between text-xs text-text-tertiary">
                                        <span>最新記事: {{ number_format($site->articles_count) }}件</span>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </section>
                @endif
            @endforeach
        </div>
    </div>
</div>
