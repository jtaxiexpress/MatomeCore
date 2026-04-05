<?php

namespace App\Filament\Resources;

use App\Filament\Resources\SiteResource\Pages;
use App\Jobs\FetchSitePastArticlesJob;
use App\Models\Site;
use App\Services\ArticleScraperService;
use Carbon\Carbon;
use Filament\Actions\Action;
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
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\HtmlString;
use Symfony\Component\DomCrawler\Crawler;

class SiteResource extends Resource
{
    protected static ?string $model = Site::class;

    protected static string|\UnitEnum|null $navigationGroup = 'マスター管理';

    protected static ?int $navigationSort = 3;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-globe-alt';

    public static function form(Schema $form): Schema
    {
        return $form->schema([
            Section::make('基本情報')
                ->description('サイトの基本的な情報を入力します。')
                ->columns(2)
                ->schema([
                    TextInput::make('name')
                        ->label('サイト名')
                        ->required()
                        ->maxLength(255),
                    TextInput::make('url')
                        ->label('サイトURL')
                        ->required()
                        ->maxLength(255)
                        ->url(),
                    Select::make('app_id')
                        ->label('対象アプリ')
                        ->relationship('app', 'name')
                        ->required()
                        ->searchable()
                        ->preload(),
                    Toggle::make('is_active')
                        ->label('クローリング有効')
                        ->default(true)
                        ->inline(false),
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

                    TagsInput::make('ng_url_keywords')
                        ->label('除外キーワード（対象外にするURL条件）')
                        ->helperText('特定の文字がURLに含まれる記事は取得しません（例: pr, promotion, special など）'),

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
                TextColumn::make('app.name')->label('アプリ')->sortable()->badge()->color('info'),
                TextColumn::make('articles_count')->counts('articles')->label('取得記事数')->badge()->sortable(),
                TextColumn::make('articles_max_created_at')
                    ->label('最終取得日時')
                    ->dateTime('Y/m/d H:i')
                    ->sortable()
                    ->badge()
                    ->color(fn ($state): string => match (true) {
                        $state === null => 'danger',
                        \Carbon\Carbon::parse($state) >= now()->subDays(3) => 'success',
                        \Carbon\Carbon::parse($state) >= now()->subDays(7) => 'warning',
                        default => 'gray',
                    }),
                TextColumn::make('name')->label('サイト名')->searchable(),
                TextColumn::make('url')->label('URL')->searchable()->limit(30),
                ToggleColumn::make('is_active')->label('ステータス'),
                TextColumn::make('created_at')->label('作成日')->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')->label('更新日')->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([])
            ->actions([
                \Filament\Actions\ActionGroup::make([
                    Action::make('test_crawl')
                        ->label('クローラ抽出テスト')
                    ->icon('heroicon-o-bug-ant')
                    ->color('warning')
                    ->action(function (Site $record) {
                        try {
                            if ($record->crawler_type === 'sitemap') {
                                $url = $record->sitemap_url;
                                if (empty($url)) {
                                    throw new \Exception('サイトマップURLが設定されていません。');
                                }

                                $response = Http::withHeaders([
                                    'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                                ])->timeout(10)->get($url);
                                if (! $response->successful()) {
                                    throw new \Exception('HTTP通信に失敗: '.$response->status());
                                }

                                $xml = simplexml_load_string($response->body());
                                if ($xml === false) {
                                    throw new \Exception('XMLのパースに失敗しました。');
                                }

                                if (isset($xml->sitemap)) {
                                    $sitemaps = $xml->sitemap;
                                    $targetSitemapUrl = (string) $sitemaps[count($sitemaps) - 1]->loc;

                                    $response = Http::withHeaders([
                                        'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                                    ])->timeout(10)->get($targetSitemapUrl);

                                    if (! $response->successful()) {
                                        throw new \Exception('子サイトマップのHTTP通信に失敗: '.$response->status());
                                    }

                                    $xml = simplexml_load_string($response->body());
                                    if ($xml === false) {
                                        throw new \Exception('子サイトマップのXMLパースに失敗しました。');
                                    }
                                }

                                $urls = [];
                                $allUrls = $xml->url ?? [];
                                foreach ($allUrls as $urlEntry) {
                                    $urls[] = (string) $urlEntry->loc;
                                }

                                $ngKeywords = $record->ng_url_keywords ?? [];
                                $siteUrl = rtrim($record->url, '/');
                                $startUrl = rtrim($record->crawl_start_url ?? '', '/');

                                $requiredSubstring = null;
                                if (! empty($record->link_selector) && preg_match('/href\*?=[\'"]([^\'"]+)[\'"]/', $record->link_selector, $matches)) {
                                    $requiredSubstring = $matches[1];
                                }

                                $urls = collect($urls)
                                    ->filter(function ($url) use ($requiredSubstring) {
                                        if ($requiredSubstring && ! str_contains($url, $requiredSubstring)) {
                                            return false;
                                        }

                                        return true;
                                    })
                                    ->reject(function ($url) use ($ngKeywords, $siteUrl, $startUrl) {
                                        $cleanUrl = rtrim($url, '/');
                                        if ($cleanUrl === $siteUrl || ($startUrl !== '' && $cleanUrl === $startUrl)) {
                                            return true;
                                        }
                                        if (str_contains($url, '/page/') || str_contains($url, '?page=')) {
                                            return true;
                                        }
                                        foreach ($ngKeywords as $ng) {
                                            if ($ng !== '' && str_contains($url, $ng)) {
                                                return true;
                                            }
                                        }

                                        return false;
                                    })
                                    ->values()
                                    ->all();
                                $count = count($urls);
                                $totalCount = $count;
                            } else {
                                $url = $record->crawl_start_url;
                                if (empty($url)) {
                                    throw new \Exception('クロール開始URLが設定されていません。');
                                }

                                $response = Http::withHeaders([
                                    'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                                ])->timeout(10)->get($url);

                                if (! $response->successful()) {
                                    throw new \Exception('HTTP通信に失敗: '.$response->status());
                                }

                                $crawler = new Crawler($response->body(), $url);

                                $listSelector = $record->list_item_selector;
                                $linkSelector = $record->link_selector;
                                $urls = [];

                                if (empty($listSelector)) {
                                    if (empty($linkSelector)) {
                                        throw new \Exception('リストブロックまたは記事リンクのセレクタが未設定です。');
                                    }
                                    $items = $crawler->filter($linkSelector);
                                    $items->each(function ($node) use (&$urls) {
                                        try {
                                            $linkUrl = null;
                                            if ($node->nodeName() === 'a') {
                                                $linkUrl = $node->link()->getUri();
                                            } elseif ($node->filter('a')->count() > 0) {
                                                $linkUrl = $node->filter('a')->first()->link()->getUri();
                                            }
                                            if ($linkUrl) {
                                                $urls[] = $linkUrl;
                                            }
                                        } catch (\Exception $e) {
                                        }
                                    });
                                } else {
                                    $items = $crawler->filter($listSelector);
                                    $items->each(function ($node) use (&$urls, $linkSelector) {
                                        try {
                                            $linkUrl = null;
                                            if (! empty($linkSelector) && $node->filter($linkSelector)->count() > 0) {
                                                $linkUrl = $node->filter($linkSelector)->first()->link()->getUri();
                                            } elseif ($node->nodeName() === 'a') {
                                                $linkUrl = $node->link()->getUri();
                                            } elseif ($node->filter('a')->count() > 0) {
                                                $linkUrl = $node->filter('a')->first()->link()->getUri();
                                            }
                                            if ($linkUrl) {
                                                $urls[] = $linkUrl;
                                            }
                                        } catch (\Exception $e) {
                                        }
                                    });
                                }

                                $nextUrl = 'なし';
                                if ($record->next_page_selector && $crawler->filter($record->next_page_selector)->count() > 0) {
                                    $nextUrl = $crawler->filter($record->next_page_selector)->first()->link()->getUri();
                                    $nextUrl = "<a href='{$nextUrl}' target='_blank' style='color:#3b82f6;'>{$nextUrl}</a>";
                                }

                                $totalCount = $items->count();

                                $ngKeywords = $record->ng_url_keywords ?? [];
                                $siteUrl = rtrim($record->url, '/');
                                $startUrl = rtrim($record->crawl_start_url ?? '', '/');

                                $requiredSubstring = null;
                                if (! empty($linkSelector) && preg_match('/href\*?=[\'"]([^\'"]+)[\'"]/', $linkSelector, $matches)) {
                                    $requiredSubstring = $matches[1];
                                }

                                $urls = collect($urls)
                                    ->filter(function ($url) use ($requiredSubstring) {
                                        if ($requiredSubstring && ! str_contains($url, $requiredSubstring)) {
                                            return false;
                                        }

                                        return true;
                                    })
                                    ->reject(function ($url) use ($ngKeywords, $siteUrl, $startUrl) {
                                        $cleanUrl = rtrim($url, '/');
                                        if ($cleanUrl === $siteUrl || ($startUrl !== '' && $cleanUrl === $startUrl)) {
                                            return true;
                                        }
                                        if (str_contains($url, '/page/') || str_contains($url, '?page=')) {
                                            return true;
                                        }
                                        foreach ($ngKeywords as $ng) {
                                            if ($ng !== '' && str_contains($url, $ng)) {
                                                return true;
                                            }
                                        }

                                        return false;
                                    })
                                    ->values()
                                    ->all();

                                $count = count($urls);
                            }

                            $previewText = collect($urls)
                                ->map(fn ($u, $index) => ($index + 1).'. <a href="'.$u.'" target="_blank" style="color:#3b82f6;">'.$u.'</a>')
                                ->implode('<br>');

                            $sampleOutput = '';
                            $samples = array_slice($urls, 0, 3);
                            if (count($samples) > 0) {
                                $sampleOutput .= '<strong>【サンプル抽出テスト (最初の3件)】</strong><br>';
                                $sampleOutput .= "<div style='max-height: 350px; overflow-y: auto; background-color: rgba(255,255,255,0.1); padding: 10px; border-radius: 6px; margin-bottom: 10px;'>";
                                foreach ($samples as $index => $u) {
                                    $num = $index + 1;
                                    $title = '未取得';
                                    $imgUrl = '未取得';
                                    $date = '未取得';
                                    $scraper = app(ArticleScraperService::class);
                                    $scrapeResult = $scraper->scrape($u);

                                    if ($scrapeResult['success']) {
                                        $title = $scrapeResult['data']['title'] ?? '取得失敗(タイトル見つからず)';

                                        $imgUrl = $scrapeResult['data']['image'] ?? null;
                                        if (empty($imgUrl)) {
                                            $imgUrl = 'なし ('.($scrapeResult['error_message'] ?? '画像見つからず').')';
                                        }

                                        $date = $scrapeResult['data']['date'] ?? null;
                                        if (empty($date)) {
                                            $date = 'なし ('.($scrapeResult['error_message'] ?? '日付見つからず').')';
                                        }
                                    } else {
                                        $title = '取得失敗('.($scrapeResult['error_message'] ?? '不明なエラー').')';
                                    }

                                    $sampleOutput .= "<strong>{$num}件目:</strong><br>";
                                    $sampleOutput .= '【タイトル】 '.htmlspecialchars($title).'<br>';
                                    $sampleOutput .= "【記事URL】 <a href='{$u}' target='_blank' style='color:#3b82f6;'>{$u}</a><br>";
                                    $sampleOutput .= '【画像URL】 '.htmlspecialchars($imgUrl).'<br>';
                                    $sampleOutput .= '【投稿日】 '.htmlspecialchars($date).'<br>';
                                    if ($index < count($samples) - 1) {
                                        $sampleOutput .= "<hr style='margin: 8px 0; border-top: 1px solid rgba(128,128,128,0.3);'>";
                                    }
                                }
                                $sampleOutput .= '</div>';
                            }

                            $nextUrlHtml = isset($nextUrl) ? "<strong>【次へボタンURL】</strong><br>{$nextUrl}<br><br>" : '';

                            $htmlBody = new HtmlString(
                                $nextUrlHtml.
                                $sampleOutput.
                                "<strong>【取得URL（全{$count}件）】</strong><br>".
                                "<div style='max-height: 200px; overflow-y: auto; padding: 10px; background-color: rgba(128,128,128,0.1); border-radius: 6px; font-size: 0.85em; white-space: nowrap;'>".
                                $previewText.
                                '</div>'
                            );

                            $titleText = "URL抽出テストに成功しました 【抽出件数】 {$totalCount}件";

                            Notification::make()
                                ->success()
                                ->title($titleText)
                                ->body($htmlBody)
                                ->persistent()
                                ->send();
                        } catch (\Exception $e) {
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
                Action::make('test_rss_fetch')
                    ->label('RSSテスト')
                    ->icon('heroicon-o-rss')
                    ->color('info')
                    ->modalHeading('RSS取得・パース結果プレビュー')
                    ->modalDescription('設定されているRSS URLから最新のフィードを取得し、タイトル、URL、公開日時、画像の抽出結果をプレビューします。')
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('閉じる')
                    ->modalWidth('6xl')
                    ->modalContent(function (Site $record) {
                        $rssUrl = $record->rss_url;
                        if (empty($rssUrl)) {
                            return view('filament.actions.rss-preview', ['error' => 'RSS URLが設定されていません。']);
                        }

                        try {
                            $response = Http::withHeaders([
                                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                                'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8',
                                'Accept-Language' => 'ja,en-US;q=0.9,en;q=0.8',
                            ])->withOptions(['verify' => false])->timeout(10)->get($rssUrl);

                            if (! $response->successful()) {
                                return view('filament.actions.rss-preview', ['error' => "HTTP通信に失敗しました (ステータスコード: {$response->status()})"]);
                            }

                            $xml = @simplexml_load_string($response->body(), 'SimpleXMLElement', LIBXML_NOCDATA);
                            if ($xml === false) {
                                return view('filament.actions.rss-preview', ['error' => 'XMLのパースに失敗しました。フィードが正しい形式か確認してください。']);
                            }

                            $items = $xml->xpath('//*[local-name()="item"] | //*[local-name()="entry"]');
                            if (! $items) {
                                return view('filament.actions.rss-preview', ['error' => '記事アイテム (<item> または <entry>) が見つかりませんでした。']);
                            }

                            $results = [];
                            $scrapedCount = 0;
                            foreach (array_slice($items, 0, 10) as $item) {
                                // ① タイトル
                                $titleStr = (string) $item->title;
                                $title = $titleStr !== '' ? trim($titleStr) : 'なし';

                                // ② URL (必ずtrimする)
                                $urlStr = (string) $item->link;
                                $url = $urlStr !== '' ? trim($urlStr) : '';
                                if (! $url && isset($item->link['href'])) {
                                    $url = trim((string) $item->link['href']);
                                }
                                if (empty($url)) {
                                    $guids = $item->xpath('.//*[local-name()="guid"] | .//*[local-name()="id"]') ?: [];
                                    if (! empty($guids) && filter_var(trim((string) $guids[0]), FILTER_VALIDATE_URL)) {
                                        $url = trim((string) $guids[0]);
                                    }
                                }
                                $url = empty($url) ? 'なし' : $url;

                                // ③ 公開日 (RSSからの取得)
                                $publishedAtRaw = (string) $item->pubDate
                                    ?: (string) $item->children('dc', true)->date
                                    ?: (string) $item->updated
                                    ?: (string) $item->published
                                    ?: (string) $item->date;

                                $date = 'なし';
                                if (! empty(trim($publishedAtRaw))) {
                                    try {
                                        $date = Carbon::parse(trim($publishedAtRaw))->toDateTimeString();
                                    } catch (\Exception $e) {
                                        $date = 'なし';
                                    }
                                }

                                // ④ 画像URL (RSSからの取得)
                                $imageUrl = 'なし';
                                if (isset($item->enclosure) && isset($item->enclosure['url'])) {
                                    $imageUrl = trim((string) $item->enclosure['url']);
                                } elseif ($item->children('media', true)->content && isset($item->children('media', true)->content->attributes()->url)) {
                                    $imageUrl = trim((string) $item->children('media', true)->content->attributes()->url);
                                } elseif ($item->children('media', true)->thumbnail && isset($item->children('media', true)->thumbnail->attributes()->url)) {
                                    $imageUrl = trim((string) $item->children('media', true)->thumbnail->attributes()->url);
                                }

                                if ($imageUrl === 'なし') {
                                    $content = (string) $item->children('content', true)->encoded ?: (string) $item->description;
                                    if (preg_match('/<img[^>]+src=[\'"]([^\'"]+)[\'"]/i', $content, $matches)) {
                                        $imageUrl = trim($matches[1]);
                                    }
                                }

                                // ⑤ 欠損データのスクレイピング補完 (最大5件まで)
                                if (($imageUrl === 'なし' || $date === 'なし') && $url !== 'なし' && $scrapedCount < 5) {
                                    $scrapedCount++;
                                    $scraper = app(ArticleScraperService::class);
                                    $scrapeResult = $scraper->scrape($url);

                                    if ($scrapeResult['success']) {
                                        if ($date === 'なし') {
                                            $date = $scrapeResult['data']['date'] ?? 'なし ('.($scrapeResult['error_message'] ?? '日付見つからず').')';
                                        }
                                        if ($imageUrl === 'なし') {
                                            $imageUrl = $scrapeResult['data']['image'] ?? 'なし ('.($scrapeResult['error_message'] ?? '画像見つからず').')';
                                        }
                                    } elseif (! empty($scrapeResult['error_message'])) {
                                        if ($date === 'なし') {
                                            $date = 'なし ('.$scrapeResult['error_message'].')';
                                        }
                                        if ($imageUrl === 'なし') {
                                            $imageUrl = 'なし ('.$scrapeResult['error_message'].')';
                                        }
                                    }
                                }

                                $results[] = [
                                    'title' => $title,
                                    'url' => $url,
                                    'date' => $date,
                                    'image' => $imageUrl,
                                ];
                            }

                            return view('filament.actions.rss-preview', ['items' => $results]);

                        } catch (\Exception $e) {
                            return view('filament.actions.rss-preview', ['error' => '通信エラーが発生しました: '.$e->getMessage()]);
                        }
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
            ->modifyQueryUsing(fn (\Illuminate\Database\Eloquent\Builder $query) => $query->withMax('articles', 'created_at'));
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ManageSites::route('/'),
        ];
    }
}
