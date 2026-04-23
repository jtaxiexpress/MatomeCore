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

<div>
    @section('title', 'RSS配信一覧 (About)')
    @section('tenant_name', 'ゆにこーんアンテナ - RSS配信一覧')

    <div class="mx-auto max-w-3xl space-y-8">
        <div class="text-center">
            <h1 class="text-2xl font-bold tracking-tight text-text-primary dark:text-white">RSS配信一覧 (About)</h1>
            <p class="mt-2 text-sm text-text-secondary dark:text-text-tertiary">
                ゆにこーんアンテナでは、各カテゴリやアプリごとの新着記事をRSS形式で配信しています。<br>
                以下のURLをRSSリーダー等に登録してご利用ください。
            </p>
        </div>

        <div class="space-y-6">
            {{-- 総合RSS --}}
            <div class="rounded-2xl border border-border/40 bg-surface-elevated p-6 shadow-sm dark:border-border-dark/40 dark:bg-surface-elevated-dark">
                <h2 class="mb-4 flex items-center gap-2 text-lg font-bold text-text-primary dark:text-white">
                    <span class="text-accent">🌐</span> 総合RSS
                </h2>
                <div class="relative">
                    <input type="text" readonly value="{{ url('/rss') }}" class="w-full rounded-lg border border-border bg-surface px-4 py-3 text-sm text-text-primary focus:border-accent focus:outline-none dark:border-border-dark dark:bg-surface-dark dark:text-white" onclick="this.select()">
                </div>
            </div>

            {{-- アプリごとのRSS --}}
            @foreach ($this->apps as $app)
                <div class="rounded-2xl border border-border/40 bg-surface-elevated p-6 shadow-sm dark:border-border-dark/40 dark:bg-surface-elevated-dark">
                    <h2 class="mb-4 flex items-center gap-2 text-lg font-bold text-text-primary dark:text-white">
                        @if($app->icon_path)
                            <img src="{{ Storage::url($app->icon_path) }}" alt="" class="size-5 rounded">
                        @else
                            <span class="text-accent">📱</span>
                        @endif
                        {{ $app->name }}
                    </h2>
                    
                    <div class="mb-6">
                        <label class="mb-2 block text-xs font-semibold text-text-secondary dark:text-text-tertiary">アプリ総合RSS</label>
                        <div class="relative">
                            <input type="text" readonly value="{{ url('/s/' . $app->api_slug . '/rss') }}" class="w-full rounded-lg border border-border bg-surface px-4 py-2.5 text-sm text-text-primary focus:border-accent focus:outline-none dark:border-border-dark dark:bg-surface-dark dark:text-white" onclick="this.select()">
                        </div>
                    </div>

                    @if ($app->categories->isNotEmpty())
                        <label class="mb-2 block text-xs font-semibold text-text-secondary dark:text-text-tertiary">カテゴリ別RSS</label>
                        <div class="space-y-3">
                            @foreach ($app->categories as $category)
                                <div class="flex flex-col gap-1 sm:flex-row sm:items-center">
                                    <span class="min-w-[120px] text-sm font-medium text-text-primary dark:text-white">
                                        {{ $category->name }}
                                    </span>
                                    <input type="text" readonly value="{{ url('/s/' . $app->api_slug . '/c/' . $category->api_slug . '/rss') }}" class="w-full flex-1 rounded-lg border border-border bg-surface px-3 py-2 text-xs text-text-primary focus:border-accent focus:outline-none dark:border-border-dark dark:bg-surface-dark dark:text-white" onclick="this.select()">
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>
            @endforeach
        </div>
    </div>
</div>
