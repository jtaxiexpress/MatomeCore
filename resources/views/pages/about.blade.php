<?php

use App\Models\AboutSection;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

new
#[Layout('layouts::app')]
class extends Component {
    /**
     * @return Collection<int, AboutSection>
     */
    #[Computed]
    public function sections(): Collection
    {
        return AboutSection::where('is_visible', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
    }
}; ?>

<div>
    @section('title', 'このサイトについて')
    @section('tenant_name', 'ゆにこーんアンテナ - このサイトについて')

    <div class="mx-auto max-w-3xl">
        {{-- Page header --}}
        <div class="mb-10 border-b border-border/40 pb-8 dark:border-border-dark/40">
            <h1 class="text-[28px] font-bold tracking-tight text-text-primary dark:text-white">
                このサイトについて
            </h1>
            <p class="mt-3 text-base leading-relaxed text-text-secondary dark:text-text-tertiary">
                ゆにこーんアンテナは、さまざまなジャンルのブログ・サイトの最新記事をまとめてお届けするアンテナサービスです。
            </p>
        </div>

        @if ($this->sections->isEmpty())
            {{-- Default content when no sections are configured --}}
            <div class="space-y-8">
                <section class="about-section">
                    <h2>ゆにこーんアンテナとは</h2>
                    <p>各分野の登録サイト様から最新記事を自動収集し、一覧表示するアンテナサイトです。登録されているサイト様の新着情報をまとめてチェックできます。</p>
                </section>
                <section class="about-section">
                    <h2>相互リンク・登録について</h2>
                    <p>サイトの登録・相互リンク申請は、<a href="{{ route('front.apply') }}" class="text-accent hover:underline">こちらのフォーム</a>よりお申し込みください。審査の上、登録をご案内いたします。</p>
                </section>
                <section class="about-section">
                    <h2>RSS配信</h2>
                    <p>各アプリ・カテゴリごとのRSSフィードを配信しています。<a href="{{ route('front.rss-index') }}" class="text-accent hover:underline">RSS一覧</a>よりご利用ください。</p>
                </section>
            </div>
        @else
            {{-- Sections from database --}}
            <div class="space-y-8">
                @foreach ($this->sections as $index => $section)
                    <section class="about-section" id="section-{{ $section->id }}">
                        <h2>{{ $section->title }}</h2>
                        <div class="about-content">
                            {!! $section->content !!}
                        </div>
                    </section>

                    @if (!$loop->last)
                        <hr class="border-border/30 dark:border-border-dark/30">
                    @endif
                @endforeach
            </div>
        @endif

        {{-- Navigation links --}}
        <div class="mt-12 flex flex-wrap gap-3 border-t border-border/40 pt-8 dark:border-border-dark/40">
            <a href="{{ route('front.apply') }}" class="inline-flex items-center gap-1.5 rounded-full border border-border/50 bg-surface-elevated px-4 py-2 text-sm font-medium text-text-secondary transition-all hover:border-accent/40 hover:text-accent dark:border-border-dark/50 dark:bg-surface-elevated-dark dark:text-text-tertiary dark:hover:text-accent" wire:navigate>
                ✉️ 相互リンク申請
            </a>
            <a href="{{ route('front.rss-index') }}" class="inline-flex items-center gap-1.5 rounded-full border border-border/50 bg-surface-elevated px-4 py-2 text-sm font-medium text-text-secondary transition-all hover:border-accent/40 hover:text-accent dark:border-border-dark/50 dark:bg-surface-elevated-dark dark:text-text-tertiary dark:hover:text-accent" wire:navigate>
                📡 RSS一覧
            </a>
            <a href="{{ route('front.sites-index') }}" class="inline-flex items-center gap-1.5 rounded-full border border-border/50 bg-surface-elevated px-4 py-2 text-sm font-medium text-text-secondary transition-all hover:border-accent/40 hover:text-accent dark:border-border-dark/50 dark:bg-surface-elevated-dark dark:text-text-tertiary dark:hover:text-accent" wire:navigate>
                🌐 登録サイト一覧
            </a>
        </div>
    </div>
</div>
