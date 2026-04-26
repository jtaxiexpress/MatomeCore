@props([
    'items' => [],
    'imageLabel' => '画像',
    'titleLabel' => 'タイトル',
    'urlLabel' => 'URL',
    'dateLabel' => '公開日時',
    'emptyMessage' => '記事が見つかりませんでした。',
    'titleFallback' => 'なし',
    'imageFallback' => 'なし',
    'urlFallback' => 'なし',
    'dateFallback' => 'なし',
])

@php
    $rows = is_array($items ?? null) ? $items : [];
@endphp

<div class="overflow-hidden rounded-2xl border border-gray-200 dark:border-gray-700">
    <div class="overflow-x-auto">
        <table class="min-w-full text-left text-sm text-gray-500 dark:text-gray-400">
            <thead class="bg-gray-50 text-xs uppercase text-gray-700 dark:bg-gray-800 dark:text-gray-400">
                <tr>
                    <th scope="col" class="border-b px-4 py-3 dark:border-gray-700">{{ $imageLabel }}</th>
                    <th scope="col" class="border-b px-4 py-3 dark:border-gray-700">{{ $titleLabel }}</th>
                    <th scope="col" class="border-b px-4 py-3 dark:border-gray-700">{{ $urlLabel }}</th>
                    <th scope="col" class="border-b px-4 py-3 dark:border-gray-700">{{ $dateLabel }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                @forelse($rows as $item)
                    @php
                        $itemArray = is_array($item) ? $item : (is_object($item) && method_exists($item, 'toArray') ? $item->toArray() : (array) $item);
                        $thumbnail = trim((string) ($itemArray['image'] ?? ''));
                        $thumbnailIsUrl = filter_var($thumbnail, FILTER_VALIDATE_URL) !== false;
                        $fallbackImageUrl = trim((string) ($itemArray['fallback_image_url'] ?? ''));
                        $fallbackImageIsUrl = filter_var($fallbackImageUrl, FILTER_VALIDATE_URL) !== false;
                        $title = trim((string) ($itemArray['title'] ?? ''));
                        $articleUrl = trim((string) ($itemArray['url'] ?? ''));
                        $articleUrlIsUrl = filter_var($articleUrl, FILTER_VALIDATE_URL) !== false;
                        $date = trim((string) ($itemArray['date'] ?? ''));
                    @endphp
                    <tr class="bg-white dark:bg-gray-900">
                        <td class="px-4 py-3 align-top">
                            @if($thumbnailIsUrl)
                                <a href="{{ $thumbnail }}" target="_blank" rel="noopener noreferrer" class="inline-block">
                                    <img
                                        src="{{ $thumbnail }}"
                                        alt="サムネイル"
                                        data-fallback-src="{{ $fallbackImageIsUrl ? $fallbackImageUrl : '' }}"
                                        onerror="const fallback = this.dataset.fallbackSrc; if (fallback && this.src !== fallback) { this.src = fallback; return; } this.onerror = null; this.style.display = 'none';"
                                        class="h-16 w-16 rounded-xl object-cover ring-1 ring-gray-200 dark:ring-gray-700"
                                    >
                                </a>
                            @else
                                <span class="text-gray-500">{{ $thumbnail !== '' ? $thumbnail : $imageFallback }}</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 align-top font-medium text-gray-900 dark:text-gray-100">{{ $title !== '' ? $title : $titleFallback }}</td>
                        <td class="break-words px-4 py-3 align-top">
                            @if($articleUrlIsUrl)
                                <a href="{{ $articleUrl }}" target="_blank" rel="noopener noreferrer" class="text-primary-600 hover:underline dark:text-primary-400">
                                    {{ $articleUrl }}
                                </a>
                            @else
                                <span class="text-gray-500">{{ $articleUrl !== '' ? $articleUrl : $urlFallback }}</span>
                            @endif
                        </td>
                        <td class="whitespace-nowrap px-4 py-3 align-top">{{ $date !== '' ? $date : $dateFallback }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="4" class="px-4 py-4 text-center text-gray-500">{{ $emptyMessage }}</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>