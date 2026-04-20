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

            <div class="mt-4 overflow-hidden rounded-2xl border border-gray-200 dark:border-gray-700">
                <div class="overflow-x-auto">
                    <table class="min-w-full text-left text-sm text-gray-500 dark:text-gray-400">
                        <thead class="bg-gray-50 text-xs uppercase text-gray-700 dark:bg-gray-800 dark:text-gray-400">
                            <tr>
                                <th scope="col" class="border-b px-4 py-3 dark:border-gray-700">画像</th>
                                <th scope="col" class="border-b px-4 py-3 dark:border-gray-700">タイトル</th>
                                <th scope="col" class="border-b px-4 py-3 dark:border-gray-700">URL</th>
                                <th scope="col" class="border-b px-4 py-3 dark:border-gray-700">公開日時</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                            @forelse($items as $item)
                                @php
                                    $thumbnail = trim((string) ($item['image'] ?? ''));
                                    $thumbnailIsUrl = filter_var($thumbnail, FILTER_VALIDATE_URL) !== false;
                                    $title = trim((string) ($item['title'] ?? ''));
                                    $url = trim((string) ($item['url'] ?? ''));
                                    $urlIsValid = filter_var($url, FILTER_VALIDATE_URL) !== false;
                                    $date = trim((string) ($item['date'] ?? ''));
                                @endphp
                                <tr class="bg-white dark:bg-gray-900">
                                    <td class="px-4 py-3 align-top">
                                        @if($thumbnailIsUrl)
                                            <a href="{{ $thumbnail }}" target="_blank" rel="noopener noreferrer" class="inline-block">
                                                <img src="{{ $thumbnail }}" alt="サムネイル" class="h-16 w-16 rounded-xl object-cover ring-1 ring-gray-200 dark:ring-gray-700">
                                            </a>
                                        @else
                                            <span class="text-gray-500">{{ $thumbnail !== '' ? $thumbnail : 'なし' }}</span>
                                        @endif
                                    </td>
                                    <td class="px-4 py-3 align-top font-medium text-gray-900 dark:text-gray-100">{{ $title !== '' ? $title : 'なし' }}</td>
                                    <td class="break-words px-4 py-3 align-top">
                                        @if($urlIsValid)
                                            <a href="{{ $url }}" target="_blank" rel="noopener noreferrer" class="text-primary-600 hover:underline dark:text-primary-400">
                                                {{ $url }}
                                            </a>
                                        @else
                                            <span class="text-gray-500">{{ $url !== '' ? $url : 'なし' }}</span>
                                        @endif
                                    </td>
                                    <td class="whitespace-nowrap px-4 py-3 align-top">{{ $date !== '' ? $date : 'なし' }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="4" class="px-4 py-4 text-center text-gray-500">
                                        記事が見つかりませんでした。
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    @endif
</div>
