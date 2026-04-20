<div class="space-y-4">
    @if(isset($error) && filled($error))
        <div class="rounded-2xl border border-rose-200 bg-rose-50 p-4 text-sm text-rose-800 dark:border-rose-900/60 dark:bg-rose-950/30 dark:text-rose-200">
            {{ $error }}
        </div>
    @else
        @php
            $items = is_array($items ?? null) ? $items : [];
            $itemCount = count($items);
        @endphp

        <div class="rounded-2xl border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-700 dark:bg-gray-950">
            <div class="flex flex-wrap items-center gap-2">
                <span class="text-xs font-semibold uppercase tracking-[0.35em] text-gray-500 dark:text-gray-400">RSS PREVIEW</span>
                <x-filament::badge color="{{ $itemCount > 0 ? 'success' : 'gray' }}">取得件数 {{ $itemCount }}件</x-filament::badge>
                <x-filament::badge color="gray">最大10件まで表示</x-filament::badge>
            </div>

            <div class="mt-4">
                <x-preview-result-table :items="$items" />
            </div>
        </div>
    @endif
</div>
