<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="scroll-smooth">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="description" content="{{ $metaDescription ?? View::getSection('tenant_name', config('app.name')) . ' - 最新まとめ記事アンテナサイト' }}">

    <title>{{ View::getSection('tenant_name', config('app.name')) }}</title>

    {{-- SEO / OGP Meta Tags --}}
    <meta property="og:title" content="{{ View::getSection('tenant_name', config('app.name')) }}" />
    <meta property="og:description" content="{{ $metaDescription ?? View::getSection('tenant_name', config('app.name')) . ' - 最新まとめ記事アンテナサイト' }}" />
    <meta property="og:type" content="website" />
    <meta property="og:url" content="{{ request()->url() }}" />
    <meta property="og:image" content="{{ asset('images/ogp.png') }}" />
    <meta property="og:site_name" content="ゆにこーんアンテナ" />

    <meta name="twitter:card" content="summary_large_image" />
    <meta name="twitter:title" content="{{ View::getSection('tenant_name', config('app.name')) }}" />
    <meta name="twitter:description" content="{{ $metaDescription ?? View::getSection('tenant_name', config('app.name')) . ' - 最新まとめ記事アンテナサイト' }}" />
    <meta name="twitter:image" content="{{ asset('images/ogp.png') }}" />

    {{-- PWA Meta Tags --}}
    <link rel="manifest" href="{{ asset('manifest.json') }}">
    <meta name="theme-color" content="#818cf8">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <meta name="apple-mobile-web-app-title" content="ゆにこーんアンテナ">
    <link rel="icon" type="image/avif" href="{{ asset('images/icon.avif') }}">
    <link rel="icon" href="{{ asset('images/favicon.ico') }}" sizes="any">
    <link rel="apple-touch-icon" href="{{ asset('images/apple-touch-icon.png') }}">

    {{-- Fonts --}}
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Noto+Sans+JP:wght@400;500;600;700&display=swap" rel="stylesheet">

    {{-- Vite Assets --}}
    @if(isset($app) && $app instanceof \App\Models\App)
        <link rel="alternate" type="application/rss+xml" title="{{ $app->name }} RSS Feed" href="{{ route('front.rss.app', $app) }}" />
    @else
        <link rel="alternate" type="application/rss+xml" title="ゆにこーんアンテナ 横断 RSS Feed" href="{{ route('front.rss.index') }}" />
    @endif
    @vite(['resources/css/app.css', 'resources/js/app.js'])

    {{-- Dark Mode Init (prevents FOUC) --}}
    <script>
        if (localStorage.theme === 'dark' || (!('theme' in localStorage) && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
            document.documentElement.classList.add('dark')
        } else {
            document.documentElement.classList.remove('dark')
        }
    </script>

    {{-- Livewire Styles --}}
    @livewireStyles
</head>

<body
    class="min-h-dvh bg-surface text-text-primary antialiased dark:bg-surface-dark dark:text-white"
    x-data="{ 
        mobileMenuOpen: false,
        theme: localStorage.theme || 'system',
        updateTheme(val) {
            this.theme = val;
            if (val === 'system') {
                localStorage.removeItem('theme');
                if (window.matchMedia('(prefers-color-scheme: dark)').matches) {
                    document.documentElement.classList.add('dark');
                } else {
                    document.documentElement.classList.remove('dark');
                }
            } else {
                localStorage.theme = val;
                if (val === 'dark') {
                    document.documentElement.classList.add('dark');
                } else {
                    document.documentElement.classList.remove('dark');
                }
            }
        }
    }"
>
    {{-- Header --}}
    <header class="sticky top-0 z-40 border-b border-border/60 bg-surface-elevated/80 backdrop-blur-xl dark:border-border-dark/60 dark:bg-surface-elevated-dark/80">
        <div class="mx-auto flex h-12 max-w-5xl items-center justify-between px-4">
            {{-- Site name --}}
            <a href="{{ url('/') }}" class="flex items-center gap-2 text-base font-bold tracking-tight transition-opacity hover:opacity-70">
                <img src="{{ asset('images/icon.avif') }}" alt="" class="size-5 shrink-0 rounded-md object-cover">
                <span>@yield('tenant_name', config('app.name'))</span>
            </a>

            @php
                $activeApps = \App\Models\App::where('is_active', true)->get();
            @endphp
            {{-- Desktop nav --}}
            <nav class="hidden items-center gap-5 text-sm font-medium text-text-secondary sm:flex dark:text-text-tertiary">
                <a href="{{ url('/') }}" class="transition-colors hover:text-text-primary dark:hover:text-white" wire:navigate>ホーム</a>

                <a href="{{ route('front.sites-index') }}" class="transition-colors hover:text-text-primary dark:hover:text-white" wire:navigate>登録サイト</a>
                <a href="{{ route('front.rss-index') }}" class="transition-colors hover:text-text-primary dark:hover:text-white" wire:navigate>RSS一覧</a>
                <a href="{{ route('front.ranking') }}" class="transition-colors hover:text-text-primary dark:hover:text-white" wire:navigate>ブログランキング</a>
                <a href="{{ route('front.apply') }}" class="transition-colors hover:text-text-primary dark:hover:text-white" wire:navigate>相互リンク申請</a>
                <a href="{{ route('front.about') }}" class="transition-colors hover:text-text-primary dark:hover:text-white" wire:navigate>このサイトについて</a>

                {{-- Theme Toggle --}}
                <div class="relative ml-2" x-data="{ open: false }">
                    <button @click="open = !open" @click.outside="open = false" type="button" class="flex items-center justify-center rounded-full p-1.5 transition-colors hover:bg-black/5 dark:hover:bg-white/10" aria-label="テーマ切替">
                        <svg x-show="theme === 'light'" class="size-4.5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 3v2.25m6.364.386l-1.591 1.591M21 12h-2.25m-.386 6.364l-1.591-1.591M12 18.75V21m-4.773-4.227l-1.591 1.591M5.25 12H3m4.227-4.773L5.636 5.636M15.75 12a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0z" /></svg>
                        <svg x-show="theme === 'dark'" x-cloak class="size-4.5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M21.752 15.002A9.718 9.718 0 0118 15.75c-5.385 0-9.75-4.365-9.75-9.75 0-1.33.266-2.597.748-3.752A9.753 9.753 0 003 11.25C3 16.635 7.365 21 12.75 21a9.753 9.753 0 009.002-5.998z" /></svg>
                        <svg x-show="theme === 'system'" x-cloak class="size-4.5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9 17.25v1.007a3 3 0 01-.879 2.122L7.5 21h9l-.621-.621A3 3 0 0115 18.257V17.25m6-12V15a2.25 2.25 0 01-2.25 2.25H5.25A2.25 2.25 0 013 15V5.25m18 0A2.25 2.25 0 0018.75 3H5.25A2.25 2.25 0 003 5.25m18 0V12a2.25 2.25 0 01-2.25 2.25H5.25A2.25 2.25 0 013 12V5.25" /></svg>
                    </button>
                    <div x-show="open" x-cloak x-transition class="absolute right-0 top-full mt-2 w-32 rounded-xl border border-border/40 bg-surface-elevated py-1 shadow-lg dark:border-border-dark/40 dark:bg-surface-elevated-dark overflow-hidden">
                        <button @click="updateTheme('light'); open = false" class="flex w-full items-center gap-2 px-3 py-2 text-sm transition-colors hover:bg-black/5 dark:hover:bg-white/10" :class="theme === 'light' ? 'text-accent' : ''">
                            <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 3v2.25m6.364.386l-1.591 1.591M21 12h-2.25m-.386 6.364l-1.591-1.591M12 18.75V21m-4.773-4.227l-1.591 1.591M5.25 12H3m4.227-4.773L5.636 5.636M15.75 12a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0z" /></svg>
                            ライト
                        </button>
                        <button @click="updateTheme('dark'); open = false" class="flex w-full items-center gap-2 px-3 py-2 text-sm transition-colors hover:bg-black/5 dark:hover:bg-white/10" :class="theme === 'dark' ? 'text-accent' : ''">
                            <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M21.752 15.002A9.718 9.718 0 0118 15.75c-5.385 0-9.75-4.365-9.75-9.75 0-1.33.266-2.597.748-3.752A9.753 9.753 0 003 11.25C3 16.635 7.365 21 12.75 21a9.753 9.753 0 009.002-5.998z" /></svg>
                            ダーク
                        </button>
                        <button @click="updateTheme('system'); open = false" class="flex w-full items-center gap-2 px-3 py-2 text-sm transition-colors hover:bg-black/5 dark:hover:bg-white/10" :class="theme === 'system' ? 'text-accent' : ''">
                            <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9 17.25v1.007a3 3 0 01-.879 2.122L7.5 21h9l-.621-.621A3 3 0 0115 18.257V17.25m6-12V15a2.25 2.25 0 01-2.25 2.25H5.25A2.25 2.25 0 013 15V5.25m18 0A2.25 2.25 0 0018.75 3H5.25A2.25 2.25 0 003 5.25m18 0V12a2.25 2.25 0 01-2.25 2.25H5.25A2.25 2.25 0 013 12V5.25" /></svg>
                            システム
                        </button>
                    </div>
                </div>
            </nav>

            {{-- Mobile hamburger --}}
            <button
                type="button"
                class="flex size-9 items-center justify-center rounded-lg transition-colors hover:bg-black/5 sm:hidden dark:hover:bg-white/10"
                @click="mobileMenuOpen = !mobileMenuOpen"
                aria-label="メニュー"
            >
                <svg class="size-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path x-show="!mobileMenuOpen" stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M3.75 6.75h16.5M3.75 12h16.5M3.75 17.25h16.5" />
                    <path x-show="mobileMenuOpen" x-cloak stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M6 18L18 6M6 6l12 12" />
                </svg>
            </button>
        </div>

        {{-- Horizontal App Navigation (Category tab style) --}}
        <div class="border-t border-border/40 bg-surface-elevated/50 backdrop-blur-xl dark:border-border-dark/40 dark:bg-surface-elevated-dark/50">
            <div class="mx-auto max-w-5xl px-4">
                <nav class="category-nav flex items-center gap-6 overflow-x-auto py-2.5 text-sm font-medium text-text-secondary dark:text-text-tertiary">
                    <a href="{{ url('/') }}" class="shrink-0 transition-colors hover:text-text-primary dark:hover:text-white {{ request()->is('/') ? 'text-accent dark:text-accent font-bold' : '' }}" wire:navigate>総合トップ</a>
                    @foreach($activeApps as $appItem)
                        <a href="{{ route('front.home', $appItem) }}" class="shrink-0 transition-colors hover:text-text-primary dark:hover:text-white {{ request()->route('app') === $appItem->api_slug ? 'text-accent dark:text-accent font-bold' : '' }}" wire:navigate>{{ $appItem->name }}</a>
                    @endforeach
                </nav>
            </div>
        </div>

        {{-- Mobile slide-down menu --}}
        <div
            x-show="mobileMenuOpen"
            x-cloak
            x-transition:enter="transition ease-out duration-200"
            x-transition:enter-start="opacity-0 -translate-y-2"
            x-transition:enter-end="opacity-100 translate-y-0"
            x-transition:leave="transition ease-in duration-150"
            x-transition:leave-start="opacity-100 translate-y-0"
            x-transition:leave-end="opacity-0 -translate-y-2"
            class="border-t border-border/40 bg-surface-elevated px-4 pb-4 pt-2 sm:hidden dark:border-border-dark/40 dark:bg-surface-elevated-dark"
            @click.outside="mobileMenuOpen = false"
        >
            <nav class="flex flex-col gap-1">
                <a href="{{ url('/') }}" class="rounded-lg px-3 py-2.5 text-sm font-medium transition-colors hover:bg-black/5 dark:hover:bg-white/10" wire:navigate>ホーム</a>

                <a href="{{ route('front.sites-index') }}" class="rounded-lg px-3 py-2.5 text-sm font-medium transition-colors hover:bg-black/5 dark:hover:bg-white/10" wire:navigate>登録サイト</a>
                <a href="{{ route('front.rss-index') }}" class="rounded-lg px-3 py-2.5 text-sm font-medium transition-colors hover:bg-black/5 dark:hover:bg-white/10" wire:navigate>RSS一覧</a>
                <a href="{{ route('front.ranking') }}" class="rounded-lg px-3 py-2.5 text-sm font-medium transition-colors hover:bg-black/5 dark:hover:bg-white/10" wire:navigate>ブログランキング</a>
                <a href="{{ route('front.apply') }}" class="rounded-lg px-3 py-2.5 text-sm font-medium transition-colors hover:bg-black/5 dark:hover:bg-white/10" wire:navigate>相互リンク申請</a>
                <a href="{{ route('front.about') }}" class="rounded-lg px-3 py-2.5 text-sm font-medium transition-colors hover:bg-black/5 dark:hover:bg-white/10" wire:navigate>このサイトについて</a>

                <div class="mt-2 flex items-center justify-between border-t border-border/40 pt-2 pb-1 dark:border-border-dark/40">
                    <span class="px-3 text-sm font-medium text-text-secondary dark:text-text-tertiary">テーマ設定</span>
                    <div class="flex items-center gap-1">
                        <button @click="updateTheme('light')" class="rounded-lg p-2 transition-colors hover:bg-black/5 dark:hover:bg-white/10" :class="theme === 'light' ? 'text-accent' : 'text-text-secondary dark:text-text-tertiary'">
                            <svg class="size-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 3v2.25m6.364.386l-1.591 1.591M21 12h-2.25m-.386 6.364l-1.591-1.591M12 18.75V21m-4.773-4.227l-1.591 1.591M5.25 12H3m4.227-4.773L5.636 5.636M15.75 12a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0z" /></svg>
                        </button>
                        <button @click="updateTheme('dark')" class="rounded-lg p-2 transition-colors hover:bg-black/5 dark:hover:bg-white/10" :class="theme === 'dark' ? 'text-accent' : 'text-text-secondary dark:text-text-tertiary'">
                            <svg class="size-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M21.752 15.002A9.718 9.718 0 0118 15.75c-5.385 0-9.75-4.365-9.75-9.75 0-1.33.266-2.597.748-3.752A9.753 9.753 0 003 11.25C3 16.635 7.365 21 12.75 21a9.753 9.753 0 009.002-5.998z" /></svg>
                        </button>
                        <button @click="updateTheme('system')" class="rounded-lg p-2 transition-colors hover:bg-black/5 dark:hover:bg-white/10" :class="theme === 'system' ? 'text-accent' : 'text-text-secondary dark:text-text-tertiary'">
                            <svg class="size-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9 17.25v1.007a3 3 0 01-.879 2.122L7.5 21h9l-.621-.621A3 3 0 0115 18.257V17.25m6-12V15a2.25 2.25 0 01-2.25 2.25H5.25A2.25 2.25 0 013 15V5.25m18 0A2.25 2.25 0 0018.75 3H5.25A2.25 2.25 0 003 5.25m18 0V12a2.25 2.25 0 01-2.25 2.25H5.25A2.25 2.25 0 013 12V5.25" /></svg>
                        </button>
                    </div>
                </div>
            </nav>
        </div>
    </header>

    {{-- Main content (2 columns on large screens) --}}
    <main class="mx-auto flex min-h-[calc(100dvh-3rem-4rem)] max-w-5xl gap-6 px-4 py-4">
        {{-- Primary Column (Feed) --}}
        <div class="min-w-0 flex-1">
            {{ $slot }}
        </div>

        {{-- Secondary Column (Sidebar Ranking) --}}
        <aside class="hidden w-[300px] shrink-0 lg:block">
            <x-sidebar-ranking />
        </aside>
    </main>

    {{-- Footer --}}
    <footer class="border-t border-border/40 bg-surface-elevated/50 dark:border-border-dark/40 dark:bg-surface-elevated-dark/50">
        <div class="mx-auto max-w-5xl px-4 py-6">
            <div class="flex flex-col items-center gap-3 text-xs text-text-secondary dark:text-text-tertiary">
                <div class="flex flex-wrap justify-center gap-4">
                    <a href="{{ url('/') }}" class="transition-colors hover:text-text-primary dark:hover:text-white">ホーム</a>
                    <a href="{{ route('front.sites-index') }}" class="transition-colors hover:text-text-primary dark:hover:text-white">登録サイト</a>
                    <a href="{{ route('front.rss-index') }}" class="transition-colors hover:text-text-primary dark:hover:text-white">RSS一覧</a>
                    <a href="{{ route('front.ranking') }}" class="transition-colors hover:text-text-primary dark:hover:text-white">ランキング</a>
                    <a href="{{ route('front.apply') }}" class="transition-colors hover:text-text-primary dark:hover:text-white">相互リンク申請</a>
                    <a href="{{ route('front.about') }}" class="transition-colors hover:text-text-primary dark:hover:text-white">このサイトについて</a>
                </div>
                <p>&copy; {{ date('Y') }} @yield('tenant_name', config('app.name'))</p>
            </div>
        </div>
    </footer>

    {{-- Livewire Scripts --}}
    @livewireScripts

    {{-- PWA Service Worker Registration --}}
    <script>
        if ('serviceWorker' in navigator) {
            window.addEventListener('load', function() {
                navigator.serviceWorker.register('/sw.js').then(function(registration) {
                    console.log('ServiceWorker registration successful with scope: ', registration.scope);
                }, function(err) {
                    console.log('ServiceWorker registration failed: ', err);
                });
            });
        }
    </script>
</body>

</html>

