<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="scroll-smooth">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="description" content="{{ $metaDescription ?? View::getSection('tenant_name', config('app.name')) . ' - 最新まとめ記事アンテナサイト' }}">

    <title>{{ isset($title) ? $title . ' | ' . View::getSection('tenant_name', config('app.name')) : View::getSection('tenant_name', config('app.name')) }}</title>

    {{-- Fonts --}}
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Noto+Sans+JP:wght@400;500;600;700&display=swap" rel="stylesheet">

    {{-- Vite Assets --}}
    @if(isset($app) && $app instanceof \App\Models\App)
        <link rel="alternate" type="application/rss+xml" title="{{ $app->name }} RSS Feed" href="{{ route('front.rss.app', $app) }}" />
    @else
        <link rel="alternate" type="application/rss+xml" title="MatomeCore 横断 RSS Feed" href="{{ route('front.rss.index') }}" />
    @endif
    @vite(['resources/css/app.css', 'resources/js/app.js'])

    {{-- Livewire Styles --}}
    @livewireStyles
</head>

<body
    class="min-h-dvh bg-surface text-text-primary antialiased dark:bg-surface-dark dark:text-white"
    x-data="{ mobileMenuOpen: false }"
>
    {{-- Header --}}
    <header class="sticky top-0 z-40 border-b border-border/60 bg-surface-elevated/80 backdrop-blur-xl dark:border-border-dark/60 dark:bg-surface-elevated-dark/80">
        <div class="mx-auto flex h-12 max-w-5xl items-center justify-between px-4">
            {{-- Site name --}}
            <a href="{{ url('/') }}" class="flex items-center gap-2 text-base font-bold tracking-tight transition-opacity hover:opacity-70">
                <span class="text-lg">📡</span>
                <span>@yield('tenant_name', config('app.name'))</span>
            </a>

            @php
                $activeApps = \App\Models\App::where('is_active', true)->get();
            @endphp
            {{-- Desktop nav --}}
            <nav class="hidden items-center gap-5 text-sm font-medium text-text-secondary sm:flex dark:text-text-tertiary">
                <a href="{{ url('/') }}" class="transition-colors hover:text-text-primary dark:hover:text-white" wire:navigate>ホーム</a>
                
                {{-- Apps dropdown --}}
                <div x-data="{ open: false }" class="relative" @click.outside="open = false">
                    <button @click="open = !open" class="flex items-center gap-1 transition-colors hover:text-text-primary dark:hover:text-white">
                        アンテナ一覧
                        <svg class="size-3 transition-transform" :class="{'rotate-180': open}" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 8.25l-7.5 7.5-7.5-7.5" /></svg>
                    </button>
                    <div x-show="open" x-cloak class="absolute left-0 mt-2 w-48 rounded-xl border border-border/60 bg-surface-elevated py-1 shadow-lg backdrop-blur-xl dark:border-border-dark/60 dark:bg-surface-elevated-dark" x-transition>
                        @foreach($activeApps as $appItem)
                            <a href="{{ route('front.home', $appItem) }}" class="block px-4 py-2 text-sm text-text-secondary transition-colors hover:bg-black/5 hover:text-text-primary dark:text-text-tertiary dark:hover:bg-white/10 dark:hover:text-white" wire:navigate>{{ $appItem->name }}</a>
                        @endforeach
                    </div>
                </div>

                <a href="{{ route('front.apply') }}" class="transition-colors hover:text-text-primary dark:hover:text-white" wire:navigate>相互リンク申請</a>
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
                
                <div class="my-2 border-l-2 border-border/40 pl-3 dark:border-border-dark/40">
                    <p class="mb-1 text-xs font-bold text-text-tertiary">アンテナ一覧</p>
                    <div class="flex flex-col gap-1">
                        @foreach($activeApps as $appItem)
                            <a href="{{ route('front.home', $appItem) }}" class="rounded-lg px-3 py-2 text-sm font-medium transition-colors hover:bg-black/5 dark:hover:bg-white/10" wire:navigate>{{ $appItem->name }}</a>
                        @endforeach
                    </div>
                </div>

                <a href="{{ route('front.apply') }}" class="rounded-lg px-3 py-2.5 text-sm font-medium transition-colors hover:bg-black/5 dark:hover:bg-white/10" wire:navigate>相互リンク申請</a>
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
                <div class="flex gap-4">
                    <a href="{{ url('/') }}" class="transition-colors hover:text-text-primary dark:hover:text-white">ホーム</a>
                </div>
                <p>&copy; {{ date('Y') }} @yield('tenant_name', config('app.name'))</p>
            </div>
        </div>
    </footer>

    {{-- Livewire Scripts --}}
    @livewireScripts
</body>

</html>

