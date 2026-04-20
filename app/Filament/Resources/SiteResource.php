<?php

namespace App\Filament\Resources;

use App\Filament\Resources\SiteResource\Pages;
use App\Jobs\FetchSitePastArticlesJob;
use App\Models\Site;
use App\Services\SiteAnalyzerService;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;
use RuntimeException;
use Throwable;

class SiteResource extends Resource
{
    protected static ?string $model = Site::class;

    protected static string|\UnitEnum|null $navigationGroup = 'コンテンツ管理';

    protected static ?int $navigationSort = 1;

    protected static ?string $navigationLabel = 'サイト管理';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-globe-alt';

    public static function form(Schema $form): Schema
    {
        return $form->schema([
            Section::make('基本情報')
                ->description('サイトの基本的な情報を入力します。')
                ->columns(1)
                ->schema([
                    TextInput::make('name')
                        ->label('サイト名')
                        ->required()
                        ->maxLength(255),
                    TextInput::make('url')
                        ->label('サイトURL')
                        ->required()
                        ->maxLength(255)
                        ->url()
                        ->hintActions([
                            Action::make('ai_infer_site_settings')
                                ->label('✨ 設定を自動推論')
                                ->icon('heroicon-o-sparkles')
                                ->color('primary')
                                ->requiresConfirmation()
                                ->modalIcon('heroicon-o-sparkles')
                                ->modalIconColor('primary')
                                ->modalHeading('自動推論結果の確認')
                                ->modalDescription('緑の判定なら承認して反映できます。黄色や赤があれば内容を見直してください。')
                                ->modalSubmitActionLabel('承認して反映')
                                ->modalCancelActionLabel('キャンセル')
                                ->modalWidth('7xl')
                                ->modalContent(function (Get $get, SiteAnalyzerService $siteAnalyzerService) {
                                    $url = trim((string) $get('url'));

                                    if ($url === '') {
                                        return view('filament.actions.site-analysis-preview', [
                                            'error' => 'サイトURLを入力してください。',
                                        ]);
                                    }

                                    try {
                                        $analysis = $siteAnalyzerService->analyze($url);
                                        $previewState = self::buildStateFromAnalysis($url, $analysis);

                                        $rssPreview = $analysis['rss_url'] !== null
                                            ? $siteAnalyzerService->previewRssFetch($previewState)
                                            : ['error' => 'RSSは検出されませんでした。'];

                                        $crawlPreview = $siteAnalyzerService->previewCrawlExtraction($previewState);

                                        return view('filament.actions.site-analysis-preview', [
                                            'analysis' => $analysis,
                                            'rssPreview' => $rssPreview,
                                            'crawlPreview' => $crawlPreview,
                                            'error' => null,
                                        ]);
                                    } catch (Throwable $e) {
                                        return view('filament.actions.site-analysis-preview', [
                                            'error' => $e->getMessage(),
                                        ]);
                                    }
                                })
                                ->action(function (Get $get, Set $set, SiteAnalyzerService $siteAnalyzerService): void {
                                    $url = trim((string) $get('url'));

                                    if ($url === '') {
                                        Notification::make()
                                            ->danger()
                                            ->title('サイトURLを入力してください')
                                            ->send();

                                        return;
                                    }

                                    try {
                                        $analysis = $siteAnalyzerService->analyze($url);

                                        $set('rss_url', $analysis['rss_url']);
                                        $set('crawler_type', $analysis['crawler_type']);
                                        $set('sitemap_url', $analysis['sitemap_url']);
                                        $set('crawl_start_url', $analysis['crawl_start_url']);
                                        $set('list_item_selector', $analysis['list_item_selector']);
                                        $set('link_selector', $analysis['link_selector']);
                                        $set('pagination_url_template', $analysis['pagination_url_template']);
                                        $set('ng_image_urls', $analysis['ng_image_urls']);

                                        $currentSiteName = trim((string) $get('name'));
                                        $inferredSiteTitle = trim((string) ($analysis['site_title'] ?? ''));

                                        if ($currentSiteName === '' && $inferredSiteTitle !== '') {
                                            $set('name', $inferredSiteTitle);
                                        }

                                        $diagnostics = collect($analysis['diagnostics'] ?? [])->implode('<br>');

                                        Notification::make()
                                            ->success()
                                            ->title('サイト設定を自動推論しました')
                                            ->body(new HtmlString($diagnostics !== '' ? $diagnostics : '推論が完了しました。'))
                                            ->send();
                                    } catch (Throwable $e) {
                                        Notification::make()
                                            ->danger()
                                            ->title('自動推論に失敗しました')
                                            ->body($e->getMessage())
                                            ->persistent()
                                            ->send();
                                    }
                                }),
                            Action::make('test_current_extraction')
                                ->label('🧪 現在の設定でテスト抽出')
                                ->icon('heroicon-o-beaker')
                                ->color('info')
                                ->action(function (Get $get, SiteAnalyzerService $siteAnalyzerService): void {
                                    $state = self::buildStateFromGet($get);

                                    if (trim((string) ($state['rss_url'] ?? '')) !== '') {
                                        $rssPreview = $siteAnalyzerService->previewRssFetch($state);

                                        if (($rssPreview['error'] ?? null) !== null) {
                                            Notification::make()
                                                ->danger()
                                                ->title('RSSテストに失敗しました')
                                                ->body((string) $rssPreview['error'])
                                                ->persistent()
                                                ->send();

                                            return;
                                        }

                                        Notification::make()
                                            ->success()
                                            ->title('RSSテストに成功しました')
                                            ->body(self::buildRssPreviewBody($rssPreview['items'] ?? []))
                                            ->persistent()
                                            ->send();

                                        return;
                                    }

                                    $crawlPreview = $siteAnalyzerService->previewCrawlExtraction($state);

                                    if (($crawlPreview['error'] ?? null) !== null) {
                                        Notification::make()
                                            ->danger()
                                            ->title('抽出に失敗しました')
                                            ->body((string) $crawlPreview['error'])
                                            ->persistent()
                                            ->send();

                                        return;
                                    }

                                    $titleText = 'URL抽出テストに成功しました 【抽出件数】 '.((int) ($crawlPreview['total_count'] ?? 0)).'件';

                                    Notification::make()
                                        ->success()
                                        ->title($titleText)
                                        ->body(self::buildCrawlPreviewBody($crawlPreview))
                                        ->persistent()
                                        ->send();
                                }),
                        ]),
                    Toggle::make('is_active')
                        ->label('クローリング有効')
                        ->default(true)
                        ->inline(false),
                ]),

            Section::make('【共通】除外・フィルタリング設定')
                ->description('日々の自動取得（RSS等）と過去記事の一括取得の両方に共通して適用される除外ルールです。')
                ->schema([
                    TagsInput::make('ng_url_keywords')
                        ->label('除外キーワード（対象外にするURL条件）')
                        ->helperText('特定の文字がURLに含まれる記事は取得しません（例: pr, promotion, special など）'),

                    TagsInput::make('ng_image_urls')
                        ->label('NGサムネイル画像URL（除外画像リスト）')
                        ->helperText('サイトのデフォルト画像（ヘッダーロゴ等）のURLをここに登録すると、その画像はサムネイルとして使用しません。完全一致で照合します。')
                        ->placeholder('https://example.com/logo.png を入力してEnter'),
                ]),

            Section::make('【定期更新】日々の最新記事取得')
                ->description('毎日、新しい記事を自動的に巡回して取得するための設定です。')
                ->schema([
                    TextInput::make('rss_url')
                        ->label('RSS URL')
                        ->placeholder('https://example.com/feed')
                        ->maxLength(255)
                        ->url()
                        ->helperText('通常はこちらのみ設定すれば自動更新が可能です。対象サイトのRSS/AtomフィードのURLをご入力ください。'),
                ]),

            Section::make('【一括取得】過去記事の抽出ルール')
                ->description('サイトに既に公開されている過去の全記事を一括で取り込む場合の設定です。')
                ->collapsed()
                ->schema([
                    Select::make('crawler_type')
                        ->label('過去記事の取得ルール')
                        ->options([
                            'html' => '一覧ページから抽出して取得する',
                            'sitemap' => 'サイトマップから一括取得する',
                        ])
                        ->default('html')
                        ->required()
                        ->live(),

                    TextInput::make('sitemap_url')
                        ->label('サイトマップURL (.xml)')
                        ->placeholder('https://example.com/sitemap.xml')
                        ->url()
                        ->required(fn ($get) => $get('crawler_type') === 'sitemap')
                        ->visible(fn ($get) => $get('crawler_type') === 'sitemap')
                        ->helperText('一括取得に使用するサイトマップ(.xml)のURLを指定してください。'),

                    TextInput::make('crawl_start_url')
                        ->label('一覧ページのURL')
                        ->placeholder('https://example.com/articles')
                        ->url()
                        ->required(fn ($get) => $get('crawler_type') === 'html')
                        ->visible(fn ($get) => $get('crawler_type') === 'html')
                        ->helperText('過去記事が並んでいる一覧ページ（通常は1ページ目）のURLを指定します。'),

                    TextInput::make('pagination_url_template')
                        ->label('「2ページ目以降」のURLルール')
                        ->placeholder('https://example.com/news/page/{page}')
                        ->helperText('変化する数字の部分を `{page}` と記述します。（例：`https://example.com/page/{page}`）')
                        ->nullable()
                        ->visible(fn ($get) => $get('crawler_type') === 'html'),

                    Section::make('高度な抽出設定（エンジニア向け設定）')
                        ->description('標準の方法でうまく記事だけを読み取れない場合、手動で目印となるCSSセレクタを指定できます。')
                        ->collapsed()
                        ->visible(fn ($get) => $get('crawler_type') === 'html')
                        ->schema([
                            TextInput::make('list_item_selector')
                                ->label('記事ブロックの場所')
                                ->placeholder('例: .article-list .item:not(.is-pr)')
                                ->nullable(),

                            TextInput::make('link_selector')
                                ->label('記事リンク（<a>タグ）の場所')
                                ->placeholder('例: a.main-link'),

                            TextInput::make('next_page_selector')
                                ->label('「次のページ」ボタンの場所')
                                ->placeholder('例: .pager .next a'),
                        ]),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label('サイト名')->searchable(),
                TextColumn::make('url')
                    ->label('URL')
                    ->url(fn (?string $state): ?string => $state, shouldOpenInNewTab: true)
                    ->searchable()
                    ->limit(30),
                TextColumn::make('articles_count')->counts('articles')->label('記事数')->badge()->sortable(),
                TextColumn::make('articles_max_created_at')
                    ->label('最終取得日')
                    ->dateTime('Y/m/d H:i')
                    ->sortable()
                    ->badge()
                    ->color(fn ($state): string => match (true) {
                        $state === null => 'danger',
                        Carbon::parse($state) >= now()->subDays(3) => 'success',
                        Carbon::parse($state) >= now()->subDays(7) => 'warning',
                        default => 'gray',
                    }),
                ToggleColumn::make('is_active')->label('ステータス'),
                TextColumn::make('created_at')->label('作成日')->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')->label('更新日')->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([])
            ->actions([
                ActionGroup::make([
                    Action::make('test_crawl')
                        ->label('クローラ抽出テスト')
                        ->icon('heroicon-o-bug-ant')
                        ->color('warning')
                        ->action(function (Site $record, SiteAnalyzerService $siteAnalyzerService): void {
                            try {
                                $preview = $siteAnalyzerService->previewCrawlExtraction(self::buildStateFromRecord($record));

                                if (($preview['error'] ?? null) !== null) {
                                    throw new RuntimeException((string) $preview['error']);
                                }

                                $titleText = 'URL抽出テストに成功しました 【抽出件数】 '.((int) ($preview['total_count'] ?? 0)).'件';

                                Notification::make()
                                    ->success()
                                    ->title($titleText)
                                    ->body(self::buildCrawlPreviewBody($preview))
                                    ->persistent()
                                    ->send();
                            } catch (Throwable $e) {
                                Notification::make()
                                    ->danger()
                                    ->title('抽出に失敗しました')
                                    ->body($e->getMessage())
                                    ->persistent()
                                    ->send();
                            }
                        })
                        ->requiresConfirmation()
                        ->modalHeading('クローラーURL抽出テスト')
                        ->modalDescription('現在の抽出設定（セレクタまたはサイトマップ）を用いて、一覧ページから記事のリンクが正しく抜き出せるかをテストします。'),
                    Action::make('fetch_past_articles')
                        ->label('過去記事の一括取得')
                        ->icon('heroicon-o-rocket-launch')
                        ->color('warning')
                        ->form([
                            Select::make('limit')
                                ->label('取得件数')
                                ->options([
                                    1 => '1件',
                                    10 => '10件',
                                    100 => '100件',
                                    0 => '全件（上限なし）',
                                ])
                                ->default(10)
                                ->required(),
                        ])
                        ->requiresConfirmation()
                        ->modalHeading('過去記事の一括取得を開始')
                        ->modalDescription('このサイトの過去記事をページネーションに沿って全て抽出し、AI処理ジョブとしてキューに登録します。大量のジョブが生成されるため、ローカルLLM環境が起動していることを確認してください。')
                        ->modalSubmitActionLabel('取得を開始する')
                        ->action(function (Site $record, array $data) {
                            FetchSitePastArticlesJob::dispatch($record, (int) $data['limit']);
                            Notification::make()
                                ->success()
                                ->title('バックグラウンドで一括取得を開始しました')
                                ->send();
                        }),
                    Action::make('reanalyze_with_ai')
                        ->label('🔄 設定を再解析・修復')
                        ->icon('heroicon-o-sparkles')
                        ->color('primary')
                        ->requiresConfirmation()
                        ->modalHeading('設定の再解析・修復')
                        ->modalDescription('現在のサイトURLを再解析し、RSS/サイトマップ/抽出セレクタを自動更新します。')
                        ->modalSubmitActionLabel('再解析して更新')
                        ->action(function (Site $record, SiteAnalyzerService $siteAnalyzerService): void {
                            try {
                                $analysis = $siteAnalyzerService->analyze($record->url);

                                $record->update([
                                    'name' => filled($record->name)
                                        ? $record->name
                                        : (($analysis['site_title'] ?? null) ?: $record->name),
                                    'rss_url' => $analysis['rss_url'],
                                    'crawler_type' => $analysis['crawler_type'],
                                    'sitemap_url' => $analysis['sitemap_url'],
                                    'crawl_start_url' => $analysis['crawl_start_url'],
                                    'list_item_selector' => $analysis['list_item_selector'],
                                    'link_selector' => $analysis['link_selector'],
                                    'pagination_url_template' => $analysis['pagination_url_template'],
                                    'ng_image_urls' => $analysis['ng_image_urls'],
                                    'failing_since' => null,
                                ]);

                                $diagnostics = collect($analysis['diagnostics'] ?? [])->implode('<br>');

                                Notification::make()
                                    ->success()
                                    ->title('再解析し、設定を更新しました')
                                    ->body(new HtmlString($diagnostics !== '' ? $diagnostics : '更新が完了しました。'))
                                    ->send();
                            } catch (Throwable $e) {
                                Notification::make()
                                    ->danger()
                                    ->title('再解析に失敗しました')
                                    ->body($e->getMessage())
                                    ->persistent()
                                    ->send();
                            }
                        }),
                    Action::make('test_rss_fetch')
                        ->label('RSSテスト')
                        ->icon('heroicon-o-rss')
                        ->color('info')
                        ->modalHeading('RSS取得・パース結果プレビュー')
                        ->modalDescription('設定されているRSS URLから最新のフィードを取得し、タイトル、URL、公開日時、画像の抽出結果をプレビューします。')
                        ->modalSubmitAction(false)
                        ->modalCancelActionLabel('閉じる')
                        ->modalWidth('6xl')
                        ->modalContent(function (Site $record, SiteAnalyzerService $siteAnalyzerService) {
                            $preview = $siteAnalyzerService->previewRssFetch(self::buildStateFromRecord($record));

                            if (($preview['error'] ?? null) !== null) {
                                return view('filament.actions.rss-preview', ['error' => $preview['error']]);
                            }

                            return view('filament.actions.rss-preview', ['items' => $preview['items'] ?? []]);
                        }),
                ])->label('テスト実行')->icon('heroicon-m-beaker'),
                EditAction::make()
                    ->modalWidth('4xl')
                    ->modalHeading('サイト情報の編集')
                    ->modalDescription('サイトの登録情報を変更します。')
                    ->modalSubmitActionLabel('更新する'),
            ])
            ->bulkActions([
                BulkActionGroup::make([DeleteBulkAction::make()]),
            ])
            ->modifyQueryUsing(fn (Builder $query) => $query->withMax('articles', 'created_at'))
            ->defaultSort('created_at', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ManageSites::route('/'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function buildStateFromRecord(Site $record): array
    {
        return [
            'url' => $record->url,
            'rss_url' => $record->rss_url,
            'crawler_type' => $record->crawler_type,
            'sitemap_url' => $record->sitemap_url,
            'crawl_start_url' => $record->crawl_start_url,
            'pagination_url_template' => $record->pagination_url_template,
            'list_item_selector' => $record->list_item_selector,
            'link_selector' => $record->link_selector,
            'next_page_selector' => $record->next_page_selector,
            'ng_url_keywords' => $record->ng_url_keywords ?? [],
            'ng_image_urls' => $record->ng_image_urls ?? [],
        ];
    }

    /**
     * @param  array<string, mixed>  $analysis
     * @return array<string, mixed>
     */
    private static function buildStateFromAnalysis(string $url, array $analysis): array
    {
        return [
            'url' => $url,
            'rss_url' => $analysis['rss_url'] ?? null,
            'crawler_type' => $analysis['crawler_type'] ?? 'html',
            'sitemap_url' => $analysis['sitemap_url'] ?? null,
            'crawl_start_url' => $analysis['crawl_start_url'] ?? $url,
            'pagination_url_template' => $analysis['pagination_url_template'] ?? null,
            'list_item_selector' => $analysis['list_item_selector'] ?? null,
            'link_selector' => $analysis['link_selector'] ?? null,
            'next_page_selector' => null,
            'ng_url_keywords' => [],
            'ng_image_urls' => $analysis['ng_image_urls'] ?? [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function buildStateFromGet(Get $get): array
    {
        return [
            'url' => (string) $get('url'),
            'rss_url' => (string) $get('rss_url'),
            'crawler_type' => (string) $get('crawler_type'),
            'sitemap_url' => (string) $get('sitemap_url'),
            'crawl_start_url' => (string) $get('crawl_start_url'),
            'pagination_url_template' => (string) $get('pagination_url_template'),
            'list_item_selector' => (string) $get('list_item_selector'),
            'link_selector' => (string) $get('link_selector'),
            'next_page_selector' => (string) $get('next_page_selector'),
            'ng_url_keywords' => $get('ng_url_keywords') ?? [],
            'ng_image_urls' => $get('ng_image_urls') ?? [],
        ];
    }

    /**
     * @param  array<string, mixed>  $preview
     */
    private static function buildCrawlPreviewBody(array $preview): HtmlString
    {
        $urls = is_array($preview['urls'] ?? null) ? $preview['urls'] : [];
        $sampleItems = is_array($preview['sample_items'] ?? null) ? $preview['sample_items'] : [];
        $nextUrl = $preview['next_url'] ?? null;
        $count = (int) ($preview['count'] ?? count($urls));

        $previewText = collect($urls)
            ->map(static fn ($url, $index): string => ($index + 1).'. <a href="'.(string) $url.'" target="_blank" style="color:#3b82f6;">'.(string) $url.'</a>')
            ->implode('<br>');

        $sampleOutput = '';

        if ($sampleItems !== []) {
            $sampleOutput .= '<strong>【サンプル抽出テスト (最初の3件)】</strong><br>';
            $sampleOutput .= "<div style='max-height: 350px; overflow-y: auto; background-color: rgba(255,255,255,0.1); padding: 10px; border-radius: 6px; margin-bottom: 10px;'>";

            foreach ($sampleItems as $index => $sample) {
                if (! is_array($sample)) {
                    continue;
                }

                $num = $index + 1;
                $title = htmlspecialchars((string) ($sample['title'] ?? '未取得'));
                $url = (string) ($sample['url'] ?? '');
                $image = htmlspecialchars((string) ($sample['image'] ?? '未取得'));
                $date = htmlspecialchars((string) ($sample['date'] ?? '未取得'));

                $sampleOutput .= "<strong>{$num}件目:</strong><br>";
                $sampleOutput .= '【タイトル】 '.$title.'<br>';
                $sampleOutput .= "【記事URL】 <a href='{$url}' target='_blank' style='color:#3b82f6;'>{$url}</a><br>";
                $sampleOutput .= '【画像URL】 '.$image.'<br>';
                $sampleOutput .= '【投稿日】 '.$date.'<br>';

                if ($index < count($sampleItems) - 1) {
                    $sampleOutput .= "<hr style='margin: 8px 0; border-top: 1px solid rgba(128,128,128,0.3);'>";
                }
            }

            $sampleOutput .= '</div>';
        }

        $nextUrlHtml = '';

        if (is_string($nextUrl) && $nextUrl !== '') {
            $nextUrlHtml = "<strong>【次へボタンURL】</strong><br><a href='{$nextUrl}' target='_blank' style='color:#3b82f6;'>{$nextUrl}</a><br><br>";
        }

        return new HtmlString(
            $nextUrlHtml.
            $sampleOutput.
            "<strong>【取得URL（全{$count}件）】</strong><br>".
            "<div style='max-height: 200px; overflow-y: auto; padding: 10px; background-color: rgba(128,128,128,0.1); border-radius: 6px; font-size: 0.85em; white-space: nowrap;'>".
            $previewText.
            '</div>'
        );
    }

    /**
     * @param  array<int, array{title: string, url: string, date: string, image: string}>  $items
     */
    private static function buildRssPreviewBody(array $items): HtmlString
    {
        if ($items === []) {
            return new HtmlString('RSSから記事が見つかりませんでした。');
        }

        $body = collect($items)
            ->take(10)
            ->values()
            ->map(function (array $item, int $index): string {
                $title = htmlspecialchars((string) ($item['title'] ?? 'なし'));
                $url = (string) ($item['url'] ?? 'なし');
                $image = htmlspecialchars((string) ($item['image'] ?? 'なし'));
                $date = htmlspecialchars((string) ($item['date'] ?? 'なし'));
                $number = $index + 1;

                return "<strong>{$number}件目:</strong><br>".
                    "【タイトル】 {$title}<br>".
                    "【URL】 <a href='{$url}' target='_blank' style='color:#3b82f6;'>{$url}</a><br>".
                    "【画像】 {$image}<br>".
                    "【公開日】 {$date}";
            })
            ->implode("<hr style='margin: 8px 0; border-top: 1px solid rgba(128,128,128,0.3);'>");

        return new HtmlString(
            "<div style='max-height: 350px; overflow-y: auto; padding: 10px; background-color: rgba(128,128,128,0.1); border-radius: 6px; font-size: 0.85em;'>".
            $body.
            '</div>'
        );
    }
}
