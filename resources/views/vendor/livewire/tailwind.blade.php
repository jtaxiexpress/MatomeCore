@php
if (! isset($scrollTo)) {
    $scrollTo = 'body';
}

$scrollIntoViewJsSnippet = ($scrollTo !== false)
    ? <<<JS
       (\$el.closest('{$scrollTo}') || document.querySelector('{$scrollTo}')).scrollIntoView()
    JS
    : '';
@endphp

<div>
    @if ($paginator->hasPages())
        <nav role="navigation" aria-label="Pagination Navigation" class="flex w-full flex-col items-center gap-3">
            <div class="flex w-full justify-between gap-2 sm:hidden">
                <span>
                    @if ($paginator->onFirstPage())
                        <span class="relative inline-flex items-center rounded-md border border-border/40 bg-white px-4 py-2 text-sm font-medium leading-5 text-gray-500 cursor-default dark:border-border-dark/40 dark:bg-gray-800 dark:text-gray-300 dark:focus:border-blue-700 dark:active:bg-gray-700 dark:active:text-gray-300">
                            {!! __('pagination.previous') !!}
                        </span>
                    @else
                        <button type="button" wire:click="previousPage('{{ $paginator->getPageName() }}')" x-on:click="{{ $scrollIntoViewJsSnippet }}" wire:loading.attr="disabled" class="relative inline-flex items-center rounded-md border border-border/40 bg-white px-4 py-2 text-sm font-medium leading-5 text-gray-700 transition ease-in-out duration-150 hover:text-gray-500 focus:outline-none focus:ring ring-blue-300 focus:border-blue-300 active:bg-gray-100 active:text-gray-700 dark:border-border-dark/40 dark:bg-gray-800 dark:text-gray-300 dark:focus:border-blue-700 dark:active:bg-gray-700 dark:active:text-gray-300">
                            {!! __('pagination.previous') !!}
                        </button>
                    @endif
                </span>

                <span>
                    @if ($paginator->hasMorePages())
                        <button type="button" wire:click="nextPage('{{ $paginator->getPageName() }}')" x-on:click="{{ $scrollIntoViewJsSnippet }}" wire:loading.attr="disabled" class="relative inline-flex items-center rounded-md border border-border/40 bg-white px-4 py-2 text-sm font-medium leading-5 text-gray-700 transition ease-in-out duration-150 hover:text-gray-500 focus:outline-none focus:ring ring-blue-300 focus:border-blue-300 active:bg-gray-100 active:text-gray-700 dark:border-border-dark/40 dark:bg-gray-800 dark:text-gray-300 dark:focus:border-blue-700 dark:active:bg-gray-700 dark:active:text-gray-300">
                            {!! __('pagination.next') !!}
                        </button>
                    @else
                        <span class="relative inline-flex items-center rounded-md border border-border/40 bg-white px-4 py-2 text-sm font-medium leading-5 text-gray-500 cursor-default dark:border-border-dark/40 dark:bg-gray-800 dark:text-gray-600">
                            {!! __('pagination.next') !!}
                        </span>
                    @endif
                </span>
            </div>

            <div class="hidden w-full sm:flex sm:justify-center">
                <div class="inline-flex items-center gap-1.5">
                    <span>
                        @if ($paginator->onFirstPage())
                            <span class="inline-flex size-9 items-center justify-center rounded-xl border border-border/40 bg-surface-elevated/60 text-text-secondary opacity-40 backdrop-blur-xl dark:border-border-dark/40 dark:bg-surface-elevated-dark/60 dark:text-text-tertiary">
                                <svg class="size-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
                            </span>
                        @else
                            <button type="button" wire:click="previousPage('{{ $paginator->getPageName() }}')" x-on:click="{{ $scrollIntoViewJsSnippet }}" wire:loading.attr="disabled" aria-label="{{ __('pagination.previous') }}" class="inline-flex size-9 items-center justify-center rounded-xl border border-border/40 bg-surface-elevated/60 text-text-secondary backdrop-blur-xl transition-all hover:border-accent/40 hover:bg-accent/5 hover:text-accent dark:border-border-dark/40 dark:bg-surface-elevated-dark/60 dark:text-text-tertiary dark:hover:text-accent">
                                <svg class="size-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
                            </button>
                        @endif
                    </span>

                    @foreach ($elements as $element)
                        @if (is_string($element))
                            <span class="inline-flex size-9 items-center justify-center text-sm text-text-secondary dark:text-text-tertiary">
                                {{ $element }}
                            </span>
                        @endif

                        @if (is_array($element))
                            @foreach ($element as $page => $url)
                                @if ($page == $paginator->currentPage())
                                    <span aria-current="page" class="inline-flex size-9 items-center justify-center rounded-xl bg-accent text-sm font-semibold text-white shadow-sm">
                                        {{ $page }}
                                    </span>
                                @else
                                    <button type="button" wire:click="gotoPage({{ $page }}, '{{ $paginator->getPageName() }}')" x-on:click="{{ $scrollIntoViewJsSnippet }}" aria-label="{{ __('Go to page :page', ['page' => $page]) }}" class="inline-flex size-9 items-center justify-center rounded-xl border border-border/40 bg-surface-elevated/60 text-sm font-medium text-text-secondary backdrop-blur-xl transition-all hover:border-accent/40 hover:bg-accent/5 hover:text-accent dark:border-border-dark/40 dark:bg-surface-elevated-dark/60 dark:text-text-tertiary dark:hover:text-accent">
                                        {{ $page }}
                                    </button>
                                @endif
                            @endforeach
                        @endif
                    @endforeach

                    <span>
                        @if ($paginator->hasMorePages())
                            <button type="button" wire:click="nextPage('{{ $paginator->getPageName() }}')" x-on:click="{{ $scrollIntoViewJsSnippet }}" wire:loading.attr="disabled" aria-label="{{ __('pagination.next') }}" class="inline-flex size-9 items-center justify-center rounded-xl border border-border/40 bg-surface-elevated/60 text-text-secondary backdrop-blur-xl transition-all hover:border-accent/40 hover:bg-accent/5 hover:text-accent dark:border-border-dark/40 dark:bg-surface-elevated-dark/60 dark:text-text-tertiary dark:hover:text-accent">
                                <svg class="size-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                            </button>
                        @else
                            <span class="inline-flex size-9 items-center justify-center rounded-xl border border-border/40 bg-surface-elevated/60 text-text-secondary opacity-40 backdrop-blur-xl dark:border-border-dark/40 dark:bg-surface-elevated-dark/60 dark:text-text-tertiary">
                                <svg class="size-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                            </span>
                        @endif
                    </span>
                </div>
            </div>

            <p class="text-xs text-text-secondary dark:text-text-tertiary">
                {{ $paginator->firstItem() }}–{{ $paginator->lastItem() }} 件 / 全 {{ number_format($paginator->total()) }} 件
            </p>
        </nav>
    @endif
</div>