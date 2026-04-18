<div>
    @if(isset($error))
        <div class="p-4 mb-4 text-sm text-red-800 rounded-lg bg-red-50 dark:bg-gray-800 dark:text-red-400">
            {{ $error }}
        </div>
    @else
        <div class="overflow-x-auto">
            <table class="w-full text-sm text-left text-gray-500 border border-gray-200 divide-y divide-gray-200 dark:text-gray-400 dark:border-gray-700 dark:divide-gray-700">
                <thead class="text-xs text-gray-700 uppercase bg-gray-50 dark:bg-gray-800 dark:text-gray-400">
                    <tr>
                        <th scope="col" class="px-4 py-3 border-x dark:border-gray-700">画像</th>
                        <th scope="col" class="px-4 py-3 border-x dark:border-gray-700">タイトル</th>
                        <th scope="col" class="px-4 py-3 border-x dark:border-gray-700">URL</th>
                        <th scope="col" class="px-4 py-3 border-x dark:border-gray-700">公開日時</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                    @forelse($items as $item)
                        <tr class="bg-white dark:bg-gray-900 border-b dark:border-gray-700">
                            <td class="px-4 py-3 border-x dark:border-gray-700 align-top">
                                @if(! str_starts_with($item['image'], 'なし'))
                                    <img src="{{ $item['image'] }}" alt="Thumbnail" class="w-20 h-20 object-cover rounded">
                                @else
                                    <span class="text-gray-400">{{ $item['image'] }}</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 border-x dark:border-gray-700 align-top font-medium text-gray-900 dark:text-white font-bold">
                                {{ $item['title'] }}
                            </td>
                            <td class="px-4 py-3 border-x dark:border-gray-700 align-top">
                                @if($item['url'] !== 'なし')
                                    <a href="{{ $item['url'] }}" target="_blank" class="text-primary-600 hover:underline break-all">
                                        {{ $item['url'] }}
                                    </a>
                                @else
                                    なし
                                @endif
                            </td>
                            <td class="px-4 py-3 border-x dark:border-gray-700 align-top whitespace-nowrap">
                                {{ $item['date'] }}
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" class="px-4 py-4 text-center text-gray-500 border-x dark:border-gray-700">
                                記事が見つかりませんでした。
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    @endif
</div>
