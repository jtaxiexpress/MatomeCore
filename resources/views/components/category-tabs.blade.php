@props([
    'categories',
    'selected' => null,
])

<div class="scrollbar-hide -mx-4 overflow-x-auto px-4" role="tablist" aria-label="カテゴリ">
    <div class="flex gap-1 pb-1">
        {{-- All (総合) tab --}}
        <button
            type="button"
            wire:click="selectCategory(null)"
            role="tab"
            aria-selected="{{ $selected === null ? 'true' : 'false' }}"
            @class([
                'shrink-0 rounded-full px-4 py-2 text-[13px] font-medium transition-all duration-200',
                'bg-text-primary text-white dark:bg-white dark:text-surface-dark' => $selected === null,
                'bg-black/5 text-text-secondary hover:bg-black/10 dark:bg-white/10 dark:text-text-tertiary dark:hover:bg-white/15' => $selected !== null,
            ])
            id="category-tab-all"
        >
            総合
        </button>

        {{-- Category tabs --}}
        @foreach ($categories as $category)
            <button
                type="button"
                wire:click="selectCategory('{{ $category->api_slug }}')"
                wire:key="cat-{{ $category->id }}"
                role="tab"
                aria-selected="{{ $selected === $category->api_slug ? 'true' : 'false' }}"
                @class([
                    'shrink-0 rounded-full px-4 py-2 text-[13px] font-medium transition-all duration-200',
                    'bg-text-primary text-white dark:bg-white dark:text-surface-dark' => $selected === $category->api_slug,
                    'bg-black/5 text-text-secondary hover:bg-black/10 dark:bg-white/10 dark:text-text-tertiary dark:hover:bg-white/15' => $selected !== $category->api_slug,
                ])
                id="category-tab-{{ $category->api_slug }}"
            >
                {{ $category->name }}
            </button>
        @endforeach
    </div>
</div>
