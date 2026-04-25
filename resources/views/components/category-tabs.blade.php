@props([
    'categories',
    'selected' => null,
])

<div class="category-nav -mx-4 overflow-x-auto px-4 pb-2" role="tablist" aria-label="カテゴリ">
    <div class="flex items-center gap-6 border-b border-border/40 dark:border-border-dark/40 pb-2">
        {{-- All (総合) tab --}}
        <button
            type="button"
            wire:click="selectCategory(null)"
            role="tab"
            aria-selected="{{ $selected === null ? 'true' : 'false' }}"
            @class([
                'shrink-0 text-sm transition-colors hover:text-text-primary dark:hover:text-white relative',
                'text-text-primary dark:text-white font-bold' => $selected === null,
                'text-text-secondary dark:text-text-tertiary font-medium' => $selected !== null,
            ])
            id="category-tab-all"
        >
            総合
            @if($selected === null)
                <div class="absolute -bottom-[9px] left-0 right-0 h-[2px] rounded-t-full bg-text-primary dark:bg-white"></div>
            @endif
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
                    'shrink-0 text-sm transition-colors hover:text-text-primary dark:hover:text-white relative',
                    'text-text-primary dark:text-white font-bold' => $selected === $category->api_slug,
                    'text-text-secondary dark:text-text-tertiary font-medium' => $selected !== $category->api_slug,
                ])
                id="category-tab-{{ $category->api_slug }}"
            >
                {{ $category->name }}
                @if($selected === $category->api_slug)
                    <div class="absolute -bottom-[9px] left-0 right-0 h-[2px] rounded-t-full bg-text-primary dark:bg-white"></div>
                @endif
            </button>
        @endforeach
    </div>
</div>
