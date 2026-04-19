<div class="space-y-6">
    @if(isset($error) && filled($error))
        <div class="p-4 text-sm text-red-800 rounded-lg bg-red-50 dark:bg-gray-800 dark:text-red-400">
            {{ $error }}
        </div>
    @else
        @php
            $analysis = $analysis ?? [];
            $rssPreview = $rssPreview ?? [];
            $crawlPreview = $crawlPreview ?? [];
            $rssItems = is_array($rssPreview['items'] ?? null) ? $rssPreview['items'] : [];
            $crawlUrls = is_array($crawlPreview['urls'] ?? null) ? $crawlPreview['urls'] : [];
            $diagnostics = is_array($analysis['diagnostics'] ?? null) ? $analysis['diagnostics'] : [];
        @endphp

        <div class="p-4 rounded-lg border border-gray-200 bg-gray-50 dark:bg-gray-900 dark:border-gray-700">
            <h3 class="mb-3 text-sm font-bold text-gray-900 dark:text-gray-100">推論結果サマリー</h3>
            <dl class="grid grid-cols-1 gap-2 text-sm md:grid-cols-2">
                <div>
                    <dt class="font-medium text-gray-600 dark:text-gray-400">解析方式</dt>
                    <dd class="text-gray-900 dark:text-gray-100">{{ $analysis['analysis_method'] ?? '不明' }}</dd>
                </div>
                <div>
                    <dt class="font-medium text-gray-600 dark:text-gray-400">RSS URL</dt>
                    <dd class="text-gray-900 dark:text-gray-100 break-all">{{ $analysis['rss_url'] ?? '未設定' }}</dd>
                </div>
                <div>
                    <dt class="font-medium text-gray-600 dark:text-gray-400">過去記事取得ルール</dt>
                    <dd class="text-gray-900 dark:text-gray-100">{{ $analysis['crawler_type'] ?? 'html' }}</dd>
                </div>
                <div>
                    <dt class="font-medium text-gray-600 dark:text-gray-400">サイトマップURL</dt>
                    <dd class="text-gray-900 dark:text-gray-100 break-all">{{ $analysis['sitemap_url'] ?? '未設定' }}</dd>
                </div>
                <div>
                    <dt class="font-medium text-gray-600 dark:text-gray-400">一覧開始URL</dt>
                    <dd class="text-gray-900 dark:text-gray-100 break-all">{{ $analysis['crawl_start_url'] ?? '未設定' }}</dd>
                </div>
                <div>
                    <dt class="font-medium text-gray-600 dark:text-gray-400">ページネーション</dt>
                    <dd class="text-gray-900 dark:text-gray-100 break-all">{{ $analysis['pagination_url_template'] ?? '未設定' }}</dd>
                </div>
            </dl>

            @if($diagnostics !== [])
                <div class="mt-3 text-xs text-gray-700 dark:text-gray-300 space-y-1">
                    @foreach($diagnostics as $diagnostic)
                        <p>・{{ $diagnostic }}</p>
                    @endforeach
                </div>
            @endif
        </div>

        <div class="space-y-2">
            <h3 class="text-sm font-bold text-gray-900 dark:text-gray-100">RSS取得テスト（最新記事）</h3>
            @if(isset($rssPreview['error']) && filled($rssPreview['error']))
                <div class="p-3 text-sm text-amber-800 rounded-lg bg-amber-50 dark:bg-gray-800 dark:text-amber-300">
                    {{ $rssPreview['error'] }}
                </div>
            @else
                <div class="overflow-x-auto border border-gray-200 rounded-lg dark:border-gray-700">
                    <table class="w-full text-sm text-left text-gray-500 dark:text-gray-400">
                        <thead class="text-xs text-gray-700 uppercase bg-gray-50 dark:bg-gray-800 dark:text-gray-400">
                            <tr>
                                <th class="px-3 py-2 border-b dark:border-gray-700">タイトル</th>
                                <th class="px-3 py-2 border-b dark:border-gray-700">URL</th>
                                <th class="px-3 py-2 border-b dark:border-gray-700">公開日時</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($rssItems as $item)
                                <tr class="bg-white dark:bg-gray-900 border-b dark:border-gray-700">
                                    <td class="px-3 py-2 align-top text-gray-900 dark:text-gray-100">{{ $item['title'] ?? 'なし' }}</td>
                                    <td class="px-3 py-2 align-top break-all">
                                        @if(($item['url'] ?? 'なし') !== 'なし')
                                            <a href="{{ $item['url'] }}" target="_blank" class="text-primary-600 hover:underline">{{ $item['url'] }}</a>
                                        @else
                                            なし
                                        @endif
                                    </td>
                                    <td class="px-3 py-2 align-top whitespace-nowrap">{{ $item['date'] ?? 'なし' }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="3" class="px-3 py-3 text-center text-gray-500">記事が見つかりませんでした。</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            @endif
        </div>

        <div class="space-y-2">
            <h3 class="text-sm font-bold text-gray-900 dark:text-gray-100">過去記事一括取得テスト</h3>
            @if(isset($crawlPreview['error']) && filled($crawlPreview['error']))
                <div class="p-3 text-sm text-red-800 rounded-lg bg-red-50 dark:bg-gray-800 dark:text-red-300">
                    {{ $crawlPreview['error'] }}
                </div>
            @else
                <div class="grid grid-cols-1 gap-2 text-sm md:grid-cols-3">
                    <div class="p-3 rounded border border-gray-200 bg-gray-50 dark:bg-gray-900 dark:border-gray-700">
                        <p class="text-gray-600 dark:text-gray-400">抽出URL数</p>
                        <p class="font-bold text-gray-900 dark:text-gray-100">{{ (int) ($crawlPreview['count'] ?? 0) }} 件</p>
                    </div>
                    <div class="p-3 rounded border border-gray-200 bg-gray-50 dark:bg-gray-900 dark:border-gray-700">
                        <p class="text-gray-600 dark:text-gray-400">合計候補数</p>
                        <p class="font-bold text-gray-900 dark:text-gray-100">{{ (int) ($crawlPreview['total_count'] ?? 0) }} 件</p>
                    </div>
                    <div class="p-3 rounded border border-gray-200 bg-gray-50 dark:bg-gray-900 dark:border-gray-700">
                        <p class="text-gray-600 dark:text-gray-400">次ページURL</p>
                        @if(filled($crawlPreview['next_url'] ?? null))
                            <a href="{{ $crawlPreview['next_url'] }}" target="_blank" class="font-bold text-primary-600 hover:underline break-all">{{ $crawlPreview['next_url'] }}</a>
                        @else
                            <p class="font-bold text-gray-900 dark:text-gray-100">なし</p>
                        @endif
                    </div>
                </div>

                <div class="overflow-x-auto border border-gray-200 rounded-lg dark:border-gray-700">
                    <table class="w-full text-sm text-left text-gray-500 dark:text-gray-400">
                        <thead class="text-xs text-gray-700 uppercase bg-gray-50 dark:bg-gray-800 dark:text-gray-400">
                            <tr>
                                <th class="px-3 py-2 border-b dark:border-gray-700">抽出URL（先頭20件）</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($crawlUrls as $crawlUrl)
                                <tr class="bg-white dark:bg-gray-900 border-b dark:border-gray-700">
                                    <td class="px-3 py-2 break-all">
                                        <a href="{{ $crawlUrl }}" target="_blank" class="text-primary-600 hover:underline">{{ $crawlUrl }}</a>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td class="px-3 py-3 text-center text-gray-500">URLが抽出できませんでした。</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    @endif
</div>
