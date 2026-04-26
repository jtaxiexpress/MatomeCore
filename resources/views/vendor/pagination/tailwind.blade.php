@if ($paginator->hasPages())
    <nav role="navigation" aria-label="{{ __('Pagination Navigation') }}" class="flex flex-col items-center gap-3">

        {{-- Mobile: prev / next only --}}
        <div class="flex w-full items-center justify-between gap-2 sm:hidden">
            @if ($paginator->onFirstPage())
                <span class="inline-flex h-9 cursor-not-allowed items-center gap-1.5 rounded-xl border border-border/40 bg-surface-elevated/60 px-4 text-sm font-medium text-text-secondary opacity-40 backdrop-blur-xl dark:border-border-dark/40 dark:bg-surface-elevated-dark/60 dark:text-text-tertiary">
                    <svg class="size-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
                    前へ
                </span>
            @else
                <a href="{{ $paginator->previousPageUrl() }}" rel="prev"
                   class="inline-flex h-9 items-center gap-1.5 rounded-xl border border-border/40 bg-surface-elevated/60 px-4 text-sm font-medium text-text-primary backdrop-blur-xl transition-all hover:border-accent/40 hover:bg-accent/5 hover:text-accent dark:border-border-dark/40 dark:bg-surface-elevated-dark/60 dark:text-white dark:hover:text-accent">
                    <svg class="size-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
                    前へ
                </a>
            @endif

            <span class="text-xs text-text-secondary dark:text-text-tertiary">
                {{ $paginator->currentPage() }} / {{ $paginator->lastPage() }}
            </span>

            @if ($paginator->hasMorePages())
                <a href="{{ $paginator->nextPageUrl() }}" rel="next"
                   class="inline-flex h-9 items-center gap-1.5 rounded-xl border border-border/40 bg-surface-elevated/60 px-4 text-sm font-medium text-text-primary backdrop-blur-xl transition-all hover:border-accent/40 hover:bg-accent/5 hover:text-accent dark:border-border-dark/40 dark:bg-surface-elevated-dark/60 dark:text-white dark:hover:text-accent">
                    次へ
                    <svg class="size-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                </a>
            @else
                <span class="inline-flex h-9 cursor-not-allowed items-center gap-1.5 rounded-xl border border-border/40 bg-surface-elevated/60 px-4 text-sm font-medium text-text-secondary opacity-40 backdrop-blur-xl dark:border-border-dark/40 dark:bg-surface-elevated-dark/60 dark:text-text-tertiary">
                    次へ
                    <svg class="size-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                </span>
            @endif
        </div>

        {{-- Desktop: full pagination --}}
        <div class="hidden items-center gap-1.5 sm:flex">

            {{-- Previous --}}
            @if ($paginator->onFirstPage())
                <span class="inline-flex size-9 cursor-not-allowed items-center justify-center rounded-xl border border-border/40 bg-surface-elevated/60 text-text-secondary opacity-40 backdrop-blur-xl dark:border-border-dark/40 dark:bg-surface-elevated-dark/60 dark:text-text-tertiary">
                    <svg class="size-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
                </span>
            @else
                <a href="{{ $paginator->previousPageUrl() }}" rel="prev"
                   aria-label="{{ __('pagination.previous') }}"
                   class="inline-flex size-9 items-center justify-center rounded-xl border border-border/40 bg-surface-elevated/60 text-text-secondary backdrop-blur-xl transition-all hover:border-accent/40 hover:bg-accent/5 hover:text-accent dark:border-border-dark/40 dark:bg-surface-elevated-dark/60 dark:text-text-tertiary dark:hover:text-accent">
                    <svg class="size-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
                </a>
            @endif

            {{-- Page numbers --}}
            @foreach ($elements as $element)
                {{-- Dots separator --}}
                @if (is_string($element))
                    <span class="inline-flex size-9 items-center justify-center text-sm text-text-secondary dark:text-text-tertiary">
                        {{ $element }}
                    </span>
                @endif

                @if (is_array($element))
                    @foreach ($element as $page => $url)
                        @if ($page == $paginator->currentPage())
                            <span aria-current="page"
                                  class="inline-flex size-9 items-center justify-center rounded-xl bg-accent text-sm font-semibold text-white shadow-sm">
                                {{ $page }}
                            </span>
                        @else
                            <a href="{{ $url }}"
                               aria-label="{{ __('Go to page :page', ['page' => $page]) }}"
                               class="inline-flex size-9 items-center justify-center rounded-xl border border-border/40 bg-surface-elevated/60 text-sm font-medium text-text-secondary backdrop-blur-xl transition-all hover:border-accent/40 hover:bg-accent/5 hover:text-accent dark:border-border-dark/40 dark:bg-surface-elevated-dark/60 dark:text-text-tertiary dark:hover:text-accent">
                                {{ $page }}
                            </a>
                        @endif
                    @endforeach
                @endif
            @endforeach

            {{-- Next --}}
            @if ($paginator->hasMorePages())
                <a href="{{ $paginator->nextPageUrl() }}" rel="next"
                   aria-label="{{ __('pagination.next') }}"
                   class="inline-flex size-9 items-center justify-center rounded-xl border border-border/40 bg-surface-elevated/60 text-text-secondary backdrop-blur-xl transition-all hover:border-accent/40 hover:bg-accent/5 hover:text-accent dark:border-border-dark/40 dark:bg-surface-elevated-dark/60 dark:text-text-tertiary dark:hover:text-accent">
                    <svg class="size-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                </a>
            @else
                <span class="inline-flex size-9 cursor-not-allowed items-center justify-center rounded-xl border border-border/40 bg-surface-elevated/60 text-text-secondary opacity-40 backdrop-blur-xl dark:border-border-dark/40 dark:bg-surface-elevated-dark/60 dark:text-text-tertiary">
                    <svg class="size-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                </span>
            @endif
        </div>

        {{-- Result count --}}
        <p class="text-xs text-text-secondary dark:text-text-tertiary">
            {{ $paginator->firstItem() }}–{{ $paginator->lastItem() }} 件 / 全 {{ number_format($paginator->total()) }} 件
        </p>

    </nav>
@endif
