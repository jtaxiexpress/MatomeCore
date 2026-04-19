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
            $rssError = is_string($rssPreview['error'] ?? null) ? (string) $rssPreview['error'] : null;
            $crawlError = is_string($crawlPreview['error'] ?? null) ? (string) $crawlPreview['error'] : null;
            $analysisMethod = (string) ($analysis['analysis_method'] ?? '不明');
            $crawlerType = (string) ($analysis['crawler_type'] ?? 'html');
            $crawlerTypeLabel = $crawlerType === 'sitemap' ? 'サイトマップ' : 'HTML抽出';
            $hasRssUrl = filled($analysis['rss_url'] ?? null);
            $hasSitemapUrl = filled($analysis['sitemap_url'] ?? null);
            $rssCount = count($rssItems);
            $crawlCount = count($crawlUrls);
            $listItemSelector = (string) ($analysis['list_item_selector'] ?? '');
            $linkSelector = (string) ($analysis['link_selector'] ?? '');
            $paginationUrlTemplate = (string) ($analysis['pagination_url_template'] ?? '');
            $rssState = $rssError !== null ? 'danger' : ($rssCount > 0 ? 'success' : ($hasRssUrl ? 'warning' : 'gray'));
            $crawlState = $crawlError !== null ? 'danger' : ($crawlCount > 0 ? 'success' : 'warning');
            $ruleState = $crawlerType === 'sitemap' && $hasSitemapUrl
                ? 'success'
                : (filled($listItemSelector) && filled($linkSelector) ? 'success' : 'warning');
            $overallState = in_array('danger', [$rssState, $crawlState], true)
                ? 'danger'
                : (in_array('warning', [$rssState, $crawlState, $ruleState], true) ? 'warning' : 'success');
            $stateLabels = [
                'success' => '承認可',
                'warning' => '要確認',
                'danger' => '失敗',
                'gray' => '未検出',
            ];
            $cardStyles = [
                'success' => 'border-emerald-200 bg-emerald-50/80 text-emerald-950 dark:border-emerald-900/60 dark:bg-emerald-950/20 dark:text-emerald-100',
                'warning' => 'border-amber-200 bg-amber-50/80 text-amber-950 dark:border-amber-900/60 dark:bg-amber-950/20 dark:text-amber-100',
                'danger' => 'border-rose-200 bg-rose-50/80 text-rose-950 dark:border-rose-900/60 dark:bg-rose-950/20 dark:text-rose-100',
                'gray' => 'border-gray-200 bg-gray-50/80 text-gray-950 dark:border-gray-700 dark:bg-gray-900/70 dark:text-gray-100',
            ];
            $pillStyles = [
                'success' => 'bg-emerald-600 text-white',
                'warning' => 'bg-amber-500 text-white',
                'danger' => 'bg-rose-600 text-white',
                'gray' => 'bg-gray-500 text-white',
            ];
            $subtleTextStyles = [
                'success' => 'text-emerald-700 dark:text-emerald-300',
                'warning' => 'text-amber-700 dark:text-amber-300',
                'danger' => 'text-rose-700 dark:text-rose-300',
                'gray' => 'text-gray-600 dark:text-gray-400',
            ];
            $summaryText = [
                'success' => 'この状態なら承認して反映できます。',
                'warning' => '一部の情報を確認してから反映してください。',
                'danger' => '取得エラーがあるため、内容を見直してください。',
            ][$overallState];
            $summaryPanelStyles = [
                'success' => 'border-emerald-200 bg-emerald-50 text-emerald-950 dark:border-emerald-900/60 dark:bg-emerald-950/20 dark:text-emerald-100',
                'warning' => 'border-amber-200 bg-amber-50 text-amber-950 dark:border-amber-900/60 dark:bg-amber-950/20 dark:text-amber-100',
                'danger' => 'border-rose-200 bg-rose-50 text-rose-950 dark:border-rose-900/60 dark:bg-rose-950/20 dark:text-rose-100',
            ][$overallState];
            $summaryPillStyles = [
                'success' => 'bg-emerald-600 text-white',
                'warning' => 'bg-amber-500 text-white',
                'danger' => 'bg-rose-600 text-white',
            ][$overallState];
            $badgeColors = [
                'success' => 'success',
                'warning' => 'warning',
                'danger' => 'danger',
                'gray' => 'gray',
            ];
        @endphp

        <x-filament::section
            icon="heroicon-o-sparkles"
            heading="AI推論結果の確認"
            description="各項目を整理して表示します。緑なら承認可、黄色なら要確認、赤なら失敗です。"
        >
            <div class="space-y-4">
                <div class="flex flex-wrap items-center gap-2">
                    <span class="text-xs font-semibold uppercase tracking-[0.35em] text-gray-500 dark:text-gray-400">AI REVIEW</span>
                    <x-filament::badge :color="$badgeColors[$overallState]">
                        {{ $stateLabels[$overallState] }}
                    </x-filament::badge>
                    <x-filament::badge :color="$badgeColors[$rssState]">RSS {{ $rssCount }}件</x-filament::badge>
                    <x-filament::badge :color="$badgeColors[$crawlState]">過去記事 {{ $crawlCount }}件</x-filament::badge>
                    <x-filament::badge :color="$badgeColors[$ruleState]">{{ $crawlerTypeLabel }}</x-filament::badge>
                </div>

                <p class="text-sm leading-6 text-gray-600 dark:text-gray-300">{{ $summaryText }}</p>

                <div class="grid gap-3 md:grid-cols-3">
                    <div class="rounded-xl border p-4 {{ $cardStyles[$rssState] }}">
                        <p class="text-xs font-semibold uppercase tracking-[0.2em]">RSS</p>
                        <p class="mt-2 text-2xl font-bold">{{ $rssCount }} 件</p>
                        <p class="mt-1 text-sm">{{ $rssError ?? ($hasRssUrl ? '最新記事の取得を確認してください。' : 'RSSは未検出ですが、必要に応じてmorss経由で補完できます。') }}</p>
                    </div>

                    <div class="rounded-xl border p-4 {{ $cardStyles[$crawlState] }}">
                        <p class="text-xs font-semibold uppercase tracking-[0.2em]">過去記事</p>
                        <p class="mt-2 text-2xl font-bold">{{ $crawlCount }} 件</p>
                        <p class="mt-1 text-sm">{{ $crawlError ?? ($crawlerTypeLabel.' で一覧抽出を構成しています。') }}</p>
                    </div>

                    <div class="rounded-xl border p-4 {{ $cardStyles[$ruleState] }}">
                        <p class="text-xs font-semibold uppercase tracking-[0.2em]">抽出条件</p>
                        <p class="mt-2 text-2xl font-bold">{{ $crawlerTypeLabel }}</p>
                        <p class="mt-1 text-sm">{{ $crawlerType === 'sitemap' ? 'サイトマップURLを使って過去記事をまとめて取得します。' : '一覧ページから記事ブロックを推論しています。' }}</p>
                    </div>
                </div>
            </div>
        </x-filament::section>

        <div class="grid gap-6 xl:grid-cols-2">
            <div class="space-y-6">
                <x-filament::section
                    icon="heroicon-o-document-text"
                    heading="反映される設定"
                    description="承認するとこの値が保存されます。"
                >
                    <dl class="grid gap-3 md:grid-cols-2">
                        <div class="rounded-xl border border-gray-200 bg-gray-50 p-3 dark:border-gray-700 dark:bg-gray-950">
                            <dt class="text-xs font-semibold uppercase tracking-[0.2em] text-gray-500 dark:text-gray-400">RSS URL</dt>
                            <dd class="mt-1 break-all text-sm text-gray-900 dark:text-gray-100">{{ $analysis['rss_url'] ?? '未設定' }}</dd>
                        </div>
                        <div class="rounded-xl border border-gray-200 bg-gray-50 p-3 dark:border-gray-700 dark:bg-gray-950">
                            <dt class="text-xs font-semibold uppercase tracking-[0.2em] text-gray-500 dark:text-gray-400">サイトマップURL</dt>
                            <dd class="mt-1 break-all text-sm text-gray-900 dark:text-gray-100">{{ $analysis['sitemap_url'] ?? '未設定' }}</dd>
                        </div>
                        <div class="rounded-xl border border-gray-200 bg-gray-50 p-3 dark:border-gray-700 dark:bg-gray-950">
                            <dt class="text-xs font-semibold uppercase tracking-[0.2em] text-gray-500 dark:text-gray-400">一覧開始URL</dt>
                            <dd class="mt-1 break-all text-sm text-gray-900 dark:text-gray-100">{{ $analysis['crawl_start_url'] ?? '未設定' }}</dd>
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
                </x-filament::section>

                <x-filament::section
                    icon="heroicon-o-information-circle"
                    heading="診断メモ"
                    description="AI が返した補足メッセージです。"
                >
                    @if($diagnostics !== [])
                        <ul class="space-y-2 text-sm text-gray-700 dark:text-gray-300">
                            @foreach($diagnostics as $diagnostic)
                                <li class="flex gap-3 rounded-xl border border-gray-200 bg-gray-50 px-3 py-2 dark:border-gray-700 dark:bg-gray-950">
                                    <span class="mt-0.5 inline-flex h-5 w-5 flex-none items-center justify-center rounded-full bg-primary-600 text-xs font-bold text-white">✓</span>
                                    <span class="leading-6">{{ $diagnostic }}</span>
                                </li>
                            @endforeach
                        </ul>
                    @else
                        <div class="rounded-xl border border-dashed border-gray-300 bg-gray-50 p-4 text-sm text-gray-500 dark:border-gray-700 dark:bg-gray-950 dark:text-gray-400">
                            診断メモはありません。
                        </div>
                    @endif
                </x-filament::section>
            </div>

            <div class="space-y-6">
                <x-filament::section
                    icon="heroicon-o-rss"
                    heading="RSS取得テスト"
                    description="最新記事の件数と取得結果を確認します。"
                >
                    <div class="flex items-center justify-between rounded-xl border border-gray-200 bg-gray-50 px-3 py-2 text-sm dark:border-gray-700 dark:bg-gray-950">
                        <span class="text-gray-600 dark:text-gray-400">取得件数</span>
                        <span class="font-semibold text-gray-900 dark:text-gray-100">{{ $rssCount }} 件</span>
                    </div>

                    @if($rssError !== null)
                        <div class="mt-4 rounded-xl border border-rose-200 bg-rose-50 p-3 text-sm text-rose-800 dark:border-rose-900/60 dark:bg-rose-950/30 dark:text-rose-200">
                            {{ $rssError }}
                        </div>
                    @else
                        <div class="mt-4 overflow-hidden rounded-xl border border-gray-200 dark:border-gray-700">
                            <table class="w-full text-left text-sm text-gray-500 dark:text-gray-400">
                                <thead class="bg-gray-50 text-xs uppercase text-gray-700 dark:bg-gray-800 dark:text-gray-400">
                                    <tr>
                                        <th class="border-b px-3 py-2 dark:border-gray-700">タイトル</th>
                                        <th class="border-b px-3 py-2 dark:border-gray-700">URL</th>
                                        <th class="border-b px-3 py-2 dark:border-gray-700">公開日時</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @forelse($rssItems as $item)
                                        <tr class="border-b bg-white dark:border-gray-700 dark:bg-gray-900">
                                            <td class="px-3 py-2 align-top text-gray-900 dark:text-gray-100">{{ $item['title'] ?? 'なし' }}</td>
                                            <td class="break-all px-3 py-2 align-top">
                                                @if(($item['url'] ?? 'なし') !== 'なし')
                                                    <a href="{{ $item['url'] }}" target="_blank" class="text-primary-600 hover:underline">{{ $item['url'] }}</a>
                                                @else
                                                    なし
                                                @endif
                                            </td>
                                            <td class="whitespace-nowrap px-3 py-2 align-top">{{ $item['date'] ?? 'なし' }}</td>
                                        </tr>
                                    @empty
                                        <tr>
                                            <td colspan="3" class="px-3 py-4 text-center text-gray-500">記事が見つかりませんでした。</td>
                                        </tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                    @endif
                </x-filament::section>

                <x-filament::section
                    icon="heroicon-o-list-bullet"
                    heading="過去記事一括取得テスト"
                    description="一覧ページまたはサイトマップから取得できるURLを確認します。"
                >
                    <div class="grid gap-3 sm:grid-cols-3">
                        <div class="rounded-xl border border-gray-200 bg-gray-50 p-3 dark:border-gray-700 dark:bg-gray-950">
                            <p class="text-xs font-semibold uppercase tracking-[0.2em] text-gray-500 dark:text-gray-400">抽出URL数</p>
                            <p class="mt-1 text-lg font-bold text-gray-900 dark:text-gray-100">{{ $crawlCount }} 件</p>
                        </div>
                        <div class="rounded-xl border border-gray-200 bg-gray-50 p-3 dark:border-gray-700 dark:bg-gray-950">
                            <p class="text-xs font-semibold uppercase tracking-[0.2em] text-gray-500 dark:text-gray-400">合計候補数</p>
                            <p class="mt-1 text-lg font-bold text-gray-900 dark:text-gray-100">{{ (int) ($crawlPreview['total_count'] ?? 0) }} 件</p>
                        </div>
                        <div class="rounded-xl border border-gray-200 bg-gray-50 p-3 dark:border-gray-700 dark:bg-gray-950">
                            <p class="text-xs font-semibold uppercase tracking-[0.2em] text-gray-500 dark:text-gray-400">次ページURL</p>
                            @if(filled($crawlPreview['next_url'] ?? null))
                                <a href="{{ $crawlPreview['next_url'] }}" target="_blank" class="mt-1 block break-all text-sm font-semibold text-primary-600 hover:underline">{{ $crawlPreview['next_url'] }}</a>
                            @else
                                <p class="mt-1 text-sm font-semibold text-gray-900 dark:text-gray-100">なし</p>
                            @endif
                        </div>
                    </div>

                    @if($crawlError !== null)
                        <div class="mt-4 rounded-xl border border-rose-200 bg-rose-50 p-3 text-sm text-rose-800 dark:border-rose-900/60 dark:bg-rose-950/30 dark:text-rose-200">
                            {{ $crawlError }}
                        </div>
                    @else
                        <div class="mt-4 overflow-hidden rounded-xl border border-gray-200 dark:border-gray-700">
                            <table class="w-full text-left text-sm text-gray-500 dark:text-gray-400">
                                <thead class="bg-gray-50 text-xs uppercase text-gray-700 dark:bg-gray-800 dark:text-gray-400">
                                    <tr>
                                        <th class="border-b px-3 py-2 dark:border-gray-700">抽出URL（先頭20件）</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @forelse($crawlUrls as $crawlUrl)
                                        <tr class="border-b bg-white dark:border-gray-700 dark:bg-gray-900">
                                            <td class="break-all px-3 py-2">
                                                <a href="{{ $crawlUrl }}" target="_blank" class="text-primary-600 hover:underline">{{ $crawlUrl }}</a>
                                            </td>
                                        </tr>
                                    @empty
                                        <tr>
                                            <td class="px-3 py-4 text-center text-gray-500">URLが抽出できませんでした。</td>
                                        </tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                    @endif
                </x-filament::section>
            </div>
        </div>
    @endif
</div>
