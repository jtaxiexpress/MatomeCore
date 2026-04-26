@props(['categories', 'selected' => null])

<div class="relative" aria-label="カテゴリナビゲーション">
    <!-- 左エッジフェード -->
    <div
        class="absolute left-0 top-0 bottom-[20px] w-8 sm:w-12 bg-gradient-to-r from-surface-elevated dark:from-surface-elevated-dark to-transparent pointer-events-none z-10">
    </div>

    <!-- 右エッジフェード -->
    <div
        class="absolute right-0 top-0 bottom-[20px] w-8 sm:w-12 bg-gradient-to-l from-surface-elevated dark:from-surface-elevated-dark to-transparent pointer-events-none z-10">
    </div>

    <div class="category-nav scrollbar-default flex items-center whitespace-nowrap overflow-x-auto px-4 pb-5 gap-2.5 sm:gap-3"
        style="-webkit-overflow-scrolling: touch;" role="tablist">
        {{-- All (総合) tab --}}
        <button type="button" wire:click="selectCategory(null)" role="tab"
            aria-selected="{{ $selected === null ? 'true' : 'false' }}" @class([
                'shrink-0 text-sm transition-all rounded-full px-5 min-h-[44px] flex items-center justify-center select-none',
                'bg-accent text-white dark:bg-white dark:text-black font-bold shadow-sm' =>
                    $selected === null,
                'bg-black/5 text-text-primary hover:bg-black/10 dark:bg-white/10 dark:text-white dark:hover:bg-white/20 font-medium' =>
                    $selected !== null,
            ])
            id="category-tab-all">
            総合
        </button>

        {{-- Category tabs --}}
        @foreach ($categories as $category)
            <button type="button" wire:click="selectCategory('{{ $category->api_slug }}')"
                wire:key="cat-{{ $category->id }}" role="tab"
                aria-selected="{{ $selected === $category->api_slug ? 'true' : 'false' }}" @class([
                    'shrink-0 text-sm transition-all rounded-full px-5 min-h-[44px] flex items-center justify-center select-none',
                    'bg-accent text-white dark:bg-white dark:text-black font-bold shadow-sm' =>
                        $selected === $category->api_slug,
                    'bg-black/5 text-text-primary hover:bg-black/10 dark:bg-white/10 dark:text-white dark:hover:bg-white/20 font-medium' =>
                        $selected !== $category->api_slug,
                ])
                id="category-tab-{{ $category->api_slug }}">
                {{ $category->name }}
            </button>
        @endforeach
    </div>
</div>
