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
            $sampleItems = is_array($crawlPreview['sample_items'] ?? null) ? $crawlPreview['sample_items'] : [];
            $diagnostics = is_array($analysis['diagnostics'] ?? null) ? $analysis['diagnostics'] : [];
            $rssError = is_string($rssPreview['error'] ?? null) ? (string) $rssPreview['error'] : null;
            $crawlError = is_string($crawlPreview['error'] ?? null) ? (string) $crawlPreview['error'] : null;
            $crawlerType = (string) ($analysis['crawler_type'] ?? 'html');
            $crawlerTypeLabel = $crawlerType === 'sitemap' ? 'サイトマップ' : 'HTML抽出';
            $inferredSiteTitle = (string) ($analysis['site_title'] ?? '');
            $hasRssUrl = filled($analysis['rss_url'] ?? null);
            $hasSitemapUrl = filled($analysis['sitemap_url'] ?? null);
            $rssCount = count($rssItems);
            $crawlCount = count($crawlUrls);
            $sampleCompleteCount = (int) ($crawlPreview['sample_complete_count'] ?? 0);
            $sampleCheckedCount = (int) ($crawlPreview['sample_checked_count'] ?? count($sampleItems));
            $listItemSelector = (string) ($analysis['list_item_selector'] ?? '');
            $linkSelector = (string) ($analysis['link_selector'] ?? '');
            $paginationUrlTemplate = (string) ($analysis['pagination_url_template'] ?? '');
            $rssState = $rssError !== null ? 'warning' : ($rssCount > 0 ? 'success' : ($hasRssUrl ? 'warning' : 'gray'));
            $isSitemapMetadataReady = $crawlerType !== 'sitemap' || $sampleCompleteCount > 0;
            $crawlState = $crawlError !== null ? 'warning' : (($crawlCount > 0 && $isSitemapMetadataReady) ? 'success' : 'warning');
            $ruleState = $crawlerType === 'sitemap' && $hasSitemapUrl
                ? 'success'
                : (filled($listItemSelector) && filled($linkSelector) ? 'success' : 'warning');
            $overallState = in_array('warning', [$rssState, $crawlState, $ruleState], true) ? 'warning' : 'success';
            $stateLabels = [
                'success' => '承認可',
                'warning' => '要確認',
                'gray' => '未検出',
            ];
            $summaryText = [
                'success' => 'この状態なら承認して反映できます。',
                'warning' => '推論は完了しています。取得テストは必要に応じて見直してください。',
            ][$overallState];
            $summaryPanelStyles = [
                'success' => 'border-emerald-200 bg-emerald-50 text-emerald-950 dark:border-emerald-900/60 dark:bg-emerald-950/20 dark:text-emerald-100',
                'warning' => 'border-amber-200 bg-amber-50 text-amber-950 dark:border-amber-900/60 dark:bg-amber-950/20 dark:text-amber-100',
            ];
            $badgeColors = [
                'success' => 'success',
                'warning' => 'warning',
                'gray' => 'gray',
            ];
        @endphp

        <x-filament::section
            icon="heroicon-o-sparkles"
            heading="自動推論結果の確認"
            description="推論結果、反映設定、診断メモをまとめて確認します。"
        >
                <div class="space-y-6">
                    <div class="rounded-2xl border p-5 shadow-sm {{ $summaryPanelStyles[$overallState] }}">
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="text-xs font-semibold uppercase tracking-[0.35em] text-current/70">推論結果</span>
                            <x-filament::badge :color="$badgeColors[$overallState]">
                                {{ $stateLabels[$overallState] }}
                            </x-filament::badge>
                            <x-filament::badge :color="$badgeColors[$rssState]">RSS {{ $rssCount }}件</x-filament::badge>
                            <x-filament::badge :color="$badgeColors[$crawlState]">過去記事 {{ $crawlCount }}件</x-filament::badge>
                            <x-filament::badge :color="$badgeColors[$ruleState]">{{ $crawlerTypeLabel }}</x-filament::badge>
                        </div>

                        <p class="mt-3 text-sm leading-6 text-current/80">
                            {{ $summaryText }}
                        </p>
                    </div>

                    <div class="grid gap-4 lg:grid-cols-2">
                        <div class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-700 dark:bg-gray-950">
                            <div class="flex items-center justify-between gap-3">
                                <h3 class="text-sm font-semibold text-gray-900 dark:text-gray-100">反映される設定</h3>
                                <span class="text-xs font-semibold uppercase tracking-[0.25em] text-gray-500 dark:text-gray-400">保存対象</span>
                            </div>

                            <dl class="mt-4 grid gap-3 sm:grid-cols-2">
                                <div class="rounded-xl border border-gray-200 bg-gray-50 p-3 sm:col-span-2 dark:border-gray-700 dark:bg-gray-950">
                                    <dt class="text-xs font-semibold uppercase tracking-[0.2em] text-gray-500 dark:text-gray-400">サイト名</dt>
                                    <dd class="mt-1 break-all text-sm text-gray-900 dark:text-gray-100">{{ $inferredSiteTitle !== '' ? $inferredSiteTitle : '未設定' }}</dd>
                                </div>
                                <div class="rounded-xl border border-gray-200 bg-gray-50 p-3 dark:border-gray-700 dark:bg-gray-950">
                                    <dt class="text-xs font-semibold uppercase tracking-[0.2em] text-gray-500 dark:text-gray-400">RSS URL</dt>
                                    <dd class="mt-1 break-all text-sm text-gray-900 dark:text-gray-100">{{ filled($analysis['rss_url'] ?? null) ? $analysis['rss_url'] : '未設定' }}</dd>
                                </div>
                                <div class="rounded-xl border border-gray-200 bg-gray-50 p-3 dark:border-gray-700 dark:bg-gray-950">
                                    <dt class="text-xs font-semibold uppercase tracking-[0.2em] text-gray-500 dark:text-gray-400">サイトマップURL</dt>
                                    <dd class="mt-1 break-all text-sm text-gray-900 dark:text-gray-100">{{ filled($analysis['sitemap_url'] ?? null) ? $analysis['sitemap_url'] : '未設定' }}</dd>
                                </div>
                                <div class="rounded-xl border border-gray-200 bg-gray-50 p-3 dark:border-gray-700 dark:bg-gray-950">
                                    <dt class="text-xs font-semibold uppercase tracking-[0.2em] text-gray-500 dark:text-gray-400">一覧開始URL</dt>
                                    <dd class="mt-1 break-all text-sm text-gray-900 dark:text-gray-100">{{ filled($analysis['crawl_start_url'] ?? null) ? $analysis['crawl_start_url'] : '未設定' }}</dd>
                                </div>
                                <div class="rounded-xl border border-gray-200 bg-gray-50 p-3 dark:border-gray-700 dark:bg-gray-950">
                                    <dt class="text-xs font-semibold uppercase tracking-[0.2em] text-gray-500 dark:text-gray-400">ページネーション</dt>
                                    <dd class="mt-1 break-all text-sm text-gray-900 dark:text-gray-100">{{ $paginationUrlTemplate !== '' ? $paginationUrlTemplate : '未設定' }}</dd>
                                </div>
                                <div class="rounded-xl border border-gray-200 bg-gray-50 p-3 dark:border-gray-700 dark:bg-gray-950">
                                    <dt class="text-xs font-semibold uppercase tracking-[0.2em] text-gray-500 dark:text-gray-400">記事ブロックの場所</dt>
                                    <dd class="mt-1 break-all font-mono text-sm text-gray-900 dark:text-gray-100">{{ $listItemSelector !== '' ? $listItemSelector : '未設定' }}</dd>
                                </div>
                                <div class="rounded-xl border border-gray-200 bg-gray-50 p-3 dark:border-gray-700 dark:bg-gray-950">
                                    <dt class="text-xs font-semibold uppercase tracking-[0.2em] text-gray-500 dark:text-gray-400">リンクの場所</dt>
                                    <dd class="mt-1 break-all font-mono text-sm text-gray-900 dark:text-gray-100">{{ $linkSelector !== '' ? $linkSelector : '未設定' }}</dd>
                                </div>
                            </dl>
                        </div>

                        <div class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-700 dark:bg-gray-950">
                            <div class="flex items-center justify-between gap-3">
                                <h3 class="text-sm font-semibold text-gray-900 dark:text-gray-100">診断メモ</h3>
                                <span class="text-xs font-semibold uppercase tracking-[0.25em] text-gray-500 dark:text-gray-400">{{ count($diagnostics) }} 件</span>
                            </div>

                            @if($diagnostics !== [])
                                <ul class="mt-4 space-y-2 text-sm text-gray-700 dark:text-gray-300">
                                    @foreach($diagnostics as $diagnostic)
                                        <li class="flex gap-3 rounded-xl border border-gray-200 bg-gray-50 px-3 py-2 dark:border-gray-700 dark:bg-gray-950">
                                            <span class="mt-0.5 inline-flex h-5 w-5 flex-none items-center justify-center rounded-full bg-primary-600 text-xs font-bold text-white">✓</span>
                                            <span class="leading-6">{{ $diagnostic }}</span>
                                        </li>
                                    @endforeach
                                </ul>
                            @else
                                <div class="mt-4 rounded-xl border border-dashed border-gray-300 bg-gray-50 p-4 text-sm text-gray-500 dark:border-gray-700 dark:bg-gray-950 dark:text-gray-400">
                                    診断メモはありません。
                                </div>
                            @endif
                        </div>
                    </div>
                </div>
        </x-filament::section>

            <div class="space-y-6">
                <x-filament::section
                    icon="heroicon-o-rss"
                    heading="RSS取得テスト"
                    description="最新記事の件数と取得結果を確認します。"
                >
                    <div class="flex flex-wrap items-center gap-2">
                        <x-filament::badge color="{{ $rssError !== null ? 'danger' : ($rssCount > 0 ? 'success' : ($hasRssUrl ? 'warning' : 'gray')) }}">
                            取得件数 {{ $rssCount }}件
                        </x-filament::badge>
                        <x-filament::badge color="gray">最新10件まで表示</x-filament::badge>
                    </div>

                    @if($rssError !== null)
                        <div class="mt-4 rounded-2xl border border-rose-200 bg-rose-50 p-4 text-sm text-rose-800 dark:border-rose-900/60 dark:bg-rose-950/30 dark:text-rose-200">
                            {{ $rssError }}
                        </div>
                    @else
                        <div class="mt-4 overflow-hidden rounded-2xl border border-gray-200 dark:border-gray-700">
                            <div class="overflow-x-auto">
                                <table class="min-w-full text-left text-sm text-gray-500 dark:text-gray-400">
                                    <thead class="bg-gray-50 text-xs uppercase text-gray-700 dark:bg-gray-800 dark:text-gray-400">
                                        <tr>
                                            <th class="border-b px-4 py-3 dark:border-gray-700">画像</th>
                                            <th class="border-b px-4 py-3 dark:border-gray-700">タイトル</th>
                                            <th class="border-b px-4 py-3 dark:border-gray-700">URL</th>
                                            <th class="border-b px-4 py-3 dark:border-gray-700">公開日時</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                                        @forelse($rssItems as $item)
                                            @php
                                                $thumbnail = trim((string) ($item['image'] ?? ''));
                                                $thumbnailIsUrl = filter_var($thumbnail, FILTER_VALIDATE_URL) !== false;
                                                $title = trim((string) ($item['title'] ?? ''));
                                                $articleUrl = trim((string) ($item['url'] ?? ''));
                                                $articleUrlIsUrl = filter_var($articleUrl, FILTER_VALIDATE_URL) !== false;
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
                                                    @if($articleUrlIsUrl)
                                                        <a href="{{ $articleUrl }}" target="_blank" rel="noopener noreferrer" class="text-primary-600 hover:underline dark:text-primary-400">
                                                            {{ $articleUrl }}
                                                        </a>
                                                    @else
                                                        <span class="text-gray-500">{{ $articleUrl !== '' ? $articleUrl : 'なし' }}</span>
                                                    @endif
                                                </td>
                                                <td class="whitespace-nowrap px-4 py-3 align-top">{{ $date !== '' ? $date : 'なし' }}</td>
                                            </tr>
                                        @empty
                                            <tr>
                                                <td colspan="4" class="px-4 py-4 text-center text-gray-500">記事が見つかりませんでした。</td>
                                            </tr>
                                        @endforelse
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    @endif
                </x-filament::section>

                <x-filament::section
                    icon="heroicon-o-list-bullet"
                    heading="過去記事一括取得テスト"
                    description="一覧ページまたはサイトマップから取得できるURLを確認します。"
                >
                    <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                        <div class="rounded-2xl border border-gray-200 bg-gray-50 p-4 dark:border-gray-700 dark:bg-gray-950">
                            <p class="text-xs font-semibold uppercase tracking-[0.2em] text-gray-500 dark:text-gray-400">抽出URL数</p>
                            <p class="mt-2 text-lg font-bold text-gray-900 dark:text-gray-100">{{ $crawlCount }} 件</p>
                        </div>
                        <div class="rounded-2xl border border-gray-200 bg-gray-50 p-4 dark:border-gray-700 dark:bg-gray-950">
                            <p class="text-xs font-semibold uppercase tracking-[0.2em] text-gray-500 dark:text-gray-400">合計候補数</p>
                            <p class="mt-2 text-lg font-bold text-gray-900 dark:text-gray-100">{{ (int) ($crawlPreview['total_count'] ?? 0) }} 件</p>
                        </div>
                        <div class="rounded-2xl border border-gray-200 bg-gray-50 p-4 dark:border-gray-700 dark:bg-gray-950">
                            <p class="text-xs font-semibold uppercase tracking-[0.2em] text-gray-500 dark:text-gray-400">完全抽出</p>
                            <p class="mt-2 text-lg font-bold text-gray-900 dark:text-gray-100">{{ $sampleCompleteCount }} / {{ $sampleCheckedCount }} 件</p>
                        </div>
                        <div class="rounded-2xl border border-gray-200 bg-gray-50 p-4 dark:border-gray-700 dark:bg-gray-950">
                            <p class="text-xs font-semibold uppercase tracking-[0.2em] text-gray-500 dark:text-gray-400">次ページURL</p>
                            @if(filled($crawlPreview['next_url'] ?? null))
                                <a href="{{ $crawlPreview['next_url'] }}" target="_blank" rel="noopener noreferrer" class="mt-2 block break-words text-sm font-semibold text-primary-600 hover:underline dark:text-primary-400">
                                    {{ $crawlPreview['next_url'] }}
                                </a>
                            @else
                                <p class="mt-2 text-sm font-semibold text-gray-900 dark:text-gray-100">なし</p>
                            @endif
                        </div>
                    </div>

                    @if($crawlError !== null)
                        <div class="mt-4 rounded-2xl border border-rose-200 bg-rose-50 p-4 text-sm text-rose-800 dark:border-rose-900/60 dark:bg-rose-950/30 dark:text-rose-200">
                            {{ $crawlError }}
                        </div>
                    @else
                        <div class="mt-4 overflow-hidden rounded-2xl border border-gray-200 dark:border-gray-700">
                            <div class="overflow-x-auto">
                                <table class="min-w-full text-left text-sm text-gray-500 dark:text-gray-400">
                                    <thead class="bg-gray-50 text-xs uppercase text-gray-700 dark:bg-gray-800 dark:text-gray-400">
                                        <tr>
                                            <th class="border-b px-4 py-3 dark:border-gray-700">画像</th>
                                            <th class="border-b px-4 py-3 dark:border-gray-700">タイトル</th>
                                            <th class="border-b px-4 py-3 dark:border-gray-700">記事URL</th>
                                            <th class="border-b px-4 py-3 dark:border-gray-700">公開日時</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                                        @forelse($sampleItems as $sampleItem)
                                            @php
                                                $sampleImage = trim((string) ($sampleItem['image'] ?? ''));
                                                $sampleImageIsUrl = filter_var($sampleImage, FILTER_VALIDATE_URL) !== false;
                                                $sampleTitle = trim((string) ($sampleItem['title'] ?? ''));
                                                $sampleUrl = trim((string) ($sampleItem['url'] ?? ''));
                                                $sampleUrlIsUrl = filter_var($sampleUrl, FILTER_VALIDATE_URL) !== false;
                                                $sampleDate = trim((string) ($sampleItem['date'] ?? ''));
                                            @endphp
                                            <tr class="bg-white dark:bg-gray-900">
                                                <td class="px-4 py-3 align-top">
                                                    @if($sampleImageIsUrl)
                                                        <a href="{{ $sampleImage }}" target="_blank" rel="noopener noreferrer" class="inline-block">
                                                            <img src="{{ $sampleImage }}" alt="サムネイル" class="h-16 w-16 rounded-xl object-cover ring-1 ring-gray-200 dark:ring-gray-700">
                                                        </a>
                                                    @else
                                                        <span class="text-gray-500">{{ $sampleImage !== '' ? $sampleImage : 'なし' }}</span>
                                                    @endif
                                                </td>
                                                <td class="px-4 py-3 align-top font-medium text-gray-900 dark:text-gray-100">{{ $sampleTitle !== '' ? $sampleTitle : '未取得' }}</td>
                                                <td class="break-words px-4 py-3 align-top">
                                                    @if($sampleUrlIsUrl)
                                                        <a href="{{ $sampleUrl }}" target="_blank" rel="noopener noreferrer" class="text-primary-600 hover:underline dark:text-primary-400">
                                                            {{ $sampleUrl }}
                                                        </a>
                                                    @else
                                                        <span class="text-gray-500">{{ $sampleUrl !== '' ? $sampleUrl : 'なし' }}</span>
                                                    @endif
                                                </td>
                                                <td class="whitespace-nowrap px-4 py-3 align-top">{{ $sampleDate !== '' ? $sampleDate : 'なし' }}</td>
                                            </tr>
                                        @empty
                                            <tr>
                                                <td colspan="4" class="px-4 py-4 text-center text-gray-500">サンプル抽出結果がありません。</td>
                                            </tr>
                                        @endforelse
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        <details class="mt-4 rounded-2xl border border-gray-200 bg-gray-50 p-4 dark:border-gray-700 dark:bg-gray-950">
                            <summary class="cursor-pointer text-sm font-semibold text-gray-900 dark:text-gray-100">
                                抽出URL一覧（先頭20件）
                            </summary>

                            <div class="mt-4 space-y-2">
                                @forelse($crawlUrls as $index => $crawlUrl)
                                    <div class="flex items-start gap-3 rounded-xl border border-gray-200 bg-white px-3 py-2 text-sm dark:border-gray-700 dark:bg-gray-900">
                                        <span class="flex-none rounded-full bg-gray-200 px-2 py-0.5 text-xs font-semibold text-gray-700 dark:bg-gray-800 dark:text-gray-300">{{ $index + 1 }}</span>
                                        <a href="{{ $crawlUrl }}" target="_blank" rel="noopener noreferrer" class="break-words text-primary-600 hover:underline dark:text-primary-400">{{ $crawlUrl }}</a>
                                    </div>
                                @empty
                                    <div class="rounded-xl border border-dashed border-gray-300 bg-white p-4 text-center text-sm text-gray-500 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-400">
                                        URLが抽出できませんでした。
                                    </div>
                                @endforelse
                            </div>
                        </details>
                    @endif
                </x-filament::section>
            </div>
    @endif
</div>
