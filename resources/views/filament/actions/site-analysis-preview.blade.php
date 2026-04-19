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

        <div class="rounded-2xl border p-5 shadow-sm {{ $summaryPanelStyles }}">
            <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                <div class="space-y-2">
                    <div class="inline-flex items-center gap-2">
                        <span class="inline-flex h-8 w-8 items-center justify-center rounded-full bg-white/80 text-primary-600 shadow-sm ring-1 ring-black/5 dark:bg-gray-950/50 dark:text-primary-300">
                            <x-heroicon-o-sparkles class="h-4 w-4" />
                        </span>
                        <p class="text-xs font-semibold uppercase tracking-[0.35em] text-gray-500/90 dark:text-gray-300/80">AI REVIEW</p>
                    </div>
                    <h3 class="text-xl font-bold text-gray-950 dark:text-white">AIで推論した設定を一目で確認</h3>
                    <p class="max-w-3xl text-sm leading-6 text-gray-700/90 dark:text-gray-300">
                        緑はそのまま承認可、黄色は要確認、赤は失敗です。RSS・過去記事・抽出条件の3点を上から確認してください。
                    </p>
                </div>

                <div class="flex flex-col items-start gap-2 lg:items-end">
                    <x-filament::badge :color="$badgeColors[$overallState]">
                        {{ $stateLabels[$overallState] }}
                    </x-filament::badge>
                    <p class="text-xs leading-5 text-gray-600/90 dark:text-gray-300">{{ $summaryText }}</p>
                </div>
            </div>

            <div class="mt-5 grid gap-3 md:grid-cols-3">
                <div class="rounded-2xl border p-4 {{ $cardStyles[$rssState] }}">
                    <div class="flex items-center justify-between gap-2">
                        <div class="flex items-center gap-2">
                            <x-heroicon-o-rss class="h-4 w-4" />
                            <p class="text-xs font-semibold uppercase tracking-[0.22em]">RSS</p>
                        </div>
                        <x-filament::badge :color="$badgeColors[$rssState]">
                            {{ $stateLabels[$rssState] }}
                        </x-filament::badge>
                    </div>
                    <p class="mt-3 text-2xl font-semibold">{{ $rssCount }} 件</p>
                    <p class="mt-1 text-sm {{ $subtleTextStyles[$rssState] }}">
                        {{ $rssError ?? ($hasRssUrl ? '最新記事の取得を確認してください。' : 'RSSは未検出ですが、必要に応じてmorss経由で補完できます。') }}
                    </p>
                </div>

                <div class="rounded-2xl border p-4 {{ $cardStyles[$crawlState] }}">
                    <div class="flex items-center justify-between gap-2">
                        <div class="flex items-center gap-2">
                            <x-heroicon-o-list-bullet class="h-4 w-4" />
                            <p class="text-xs font-semibold uppercase tracking-[0.22em]">過去記事</p>
                        </div>
                        <x-filament::badge :color="$badgeColors[$crawlState]">
                            {{ $stateLabels[$crawlState] }}
                        </x-filament::badge>
                    </div>
                    <p class="mt-3 text-2xl font-semibold">{{ $crawlCount }} 件</p>
                    <p class="mt-1 text-sm {{ $subtleTextStyles[$crawlState] }}">
                        {{ $crawlError ?? ($crawlerTypeLabel.' で一覧抽出を構成しています。') }}
                    </p>
                </div>

                <div class="rounded-2xl border p-4 {{ $cardStyles[$ruleState] }}">
                    <div class="flex items-center justify-between gap-2">
                        <div class="flex items-center gap-2">
                            <x-heroicon-o-document-text class="h-4 w-4" />
                            <p class="text-xs font-semibold uppercase tracking-[0.22em]">抽出条件</p>
                        </div>
                        <x-filament::badge :color="$badgeColors[$ruleState]">
                            {{ $stateLabels[$ruleState] }}
                        </x-filament::badge>
                    </div>
                    <p class="mt-3 text-2xl font-semibold">{{ $crawlerTypeLabel }}</p>
                    <p class="mt-1 text-sm {{ $subtleTextStyles[$ruleState] }}">
                        {{ $crawlerType === 'sitemap' ? 'サイトマップURLを使って過去記事をまとめて取得します。' : '一覧ページから記事ブロックを推論しています。' }}
                    </p>
                </div>
            </div>
        </div>

        <div class="grid gap-6 xl:grid-cols-[1.1fr_0.9fr]">
            <div class="space-y-6">
                <div class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-700 dark:bg-gray-900">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div class="flex items-center gap-2">
                            <span class="inline-flex h-8 w-8 items-center justify-center rounded-full bg-primary-50 text-primary-600 ring-1 ring-primary-100 dark:bg-primary-950/30 dark:text-primary-300 dark:ring-primary-900/50">
                                <x-heroicon-o-document-text class="h-4 w-4" />
                            </span>
                            <div>
                            <h4 class="text-sm font-semibold text-gray-900 dark:text-gray-100">反映される設定</h4>
                            <p class="text-xs text-gray-500 dark:text-gray-400">承認するとこの値が保存されます。</p>
                            </div>
                        </div>
                        <x-filament::badge :color="$badgeColors[$overallState]">
                            {{ $analysisMethod }}
                        </x-filament::badge>
                    </div>

                    <dl class="mt-4 grid gap-4 md:grid-cols-2">
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
                </div>

                <div class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-700 dark:bg-gray-900">
                    <div class="flex items-center justify-between gap-3">
                        <div class="flex items-center gap-2">
                            <span class="inline-flex h-8 w-8 items-center justify-center rounded-full bg-gray-50 text-gray-600 ring-1 ring-gray-200 dark:bg-gray-950/50 dark:text-gray-300 dark:ring-gray-700">
                                <x-heroicon-o-information-circle class="h-4 w-4" />
                            </span>
                            <div>
                            <h4 class="text-sm font-semibold text-gray-900 dark:text-gray-100">診断メモ</h4>
                            <p class="text-xs text-gray-500 dark:text-gray-400">AI が返した補足メッセージです。</p>
                            </div>
                        </div>
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

            <div class="space-y-6">
                <div class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-700 dark:bg-gray-900">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div class="flex items-center gap-2">
                            <span class="inline-flex h-8 w-8 items-center justify-center rounded-full bg-sky-50 text-sky-600 ring-1 ring-sky-100 dark:bg-sky-950/30 dark:text-sky-300 dark:ring-sky-900/50">
                                <x-heroicon-o-rss class="h-4 w-4" />
                            </span>
                            <div>
                            <h4 class="text-sm font-semibold text-gray-900 dark:text-gray-100">RSS取得テスト</h4>
                            <p class="text-xs text-gray-500 dark:text-gray-400">最新記事の件数と取得結果を確認します。</p>
                            </div>
                        </div>
                        <x-filament::badge :color="$badgeColors[$rssState]">
                            {{ $stateLabels[$rssState] }}
                        </x-filament::badge>
                    </div>

                    <div class="mt-4 flex items-center justify-between rounded-xl border border-gray-200 bg-gray-50 px-3 py-2 text-sm dark:border-gray-700 dark:bg-gray-950">
                        <span class="text-gray-600 dark:text-gray-400">取得件数</span>
                        <span class="font-semibold text-gray-900 dark:text-gray-100">{{ $rssCount }} 件</span>
                    </div>

                    @if($rssError !== null)
                        <div class="mt-4 rounded-xl border border-rose-200 bg-rose-50 p-3 text-sm text-rose-800 dark:border-rose-900/60 dark:bg-rose-950/30 dark:text-rose-200">
                            {{ $rssError }}
                        </div>
                    @else
                        <div class="mt-4 overflow-hidden rounded-xl border border-gray-200 dark:border-gray-700">
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
                                            <td colspan="3" class="px-3 py-4 text-center text-gray-500">記事が見つかりませんでした。</td>
                                        </tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                    @endif
                </div>

                <div class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-700 dark:bg-gray-900">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div class="flex items-center gap-2">
                            <span class="inline-flex h-8 w-8 items-center justify-center rounded-full bg-violet-50 text-violet-600 ring-1 ring-violet-100 dark:bg-violet-950/30 dark:text-violet-300 dark:ring-violet-900/50">
                                <x-heroicon-o-list-bullet class="h-4 w-4" />
                            </span>
                            <div>
                            <h4 class="text-sm font-semibold text-gray-900 dark:text-gray-100">過去記事一括取得テスト</h4>
                            <p class="text-xs text-gray-500 dark:text-gray-400">一覧ページまたはサイトマップから取得できるURLを確認します。</p>
                            </div>
                        </div>
                        <x-filament::badge :color="$badgeColors[$crawlState]">
                            {{ $stateLabels[$crawlState] }}
                        </x-filament::badge>
                    </div>

                    <div class="mt-4 grid gap-3 sm:grid-cols-3">
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
                                            <td class="px-3 py-4 text-center text-gray-500">URLが抽出できませんでした。</td>
                                        </tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                    @endif
                </div>
            </div>
        </div>
    @endif
</div>
