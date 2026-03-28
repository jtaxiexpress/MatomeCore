<?php

namespace App\Filament\Resources;

use App\Filament\Resources\SiteResource\Pages;
use App\Models\Site;
use Filament\Actions\Action;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\TagsInput;
use Filament\Schemas\Schema;
use Filament\Resources\Resource;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class SiteResource extends Resource
{
    protected static ?string $model = Site::class;
    protected static string|\UnitEnum|null $navigationGroup = 'マスター管理';
    protected static ?int $navigationSort = 3;
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-globe-alt';

    public static function form(Schema $form): Schema
    {
        return $form->schema([
            Section::make('クロール対象サイト基本情報')
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
                    TextInput::make('rss_url')
                        ->label('RSS URL')
                        ->placeholder('https://example.com/feed')
                        ->maxLength(255)
                        ->url(),
                ]),
            Section::make('設定と所属')
                ->schema([
                    Select::make('app_id')
                        ->label('配信アプリ')
                        ->relationship('app', 'name')
                        ->required()
                        ->searchable()
                        ->preload(),
                    Toggle::make('is_active')
                        ->label('クローリング有効')
                        ->default(true)
                        ->inline(false),
                ]),
            Section::make('クローラー設定')
                ->description('サイトから記事を自動取得するための詳細設定')
                ->schema([
                    Select::make('crawler_type')
                        ->label('解析モード')
                        ->options([
                            'html' => 'HTML解析モード（DOM解析）',
                            'sitemap' => 'サイトマップモード（XML）',
                        ])
                        ->default('html')
                        ->required()
                        ->live(),

                    TextInput::make('sitemap_url')
                        ->label('サイトマップURL (.xml)')
                        ->placeholder('https://example.com/sitemap.xml')
                        ->url()
                        ->visible(fn ($get) => $get('crawler_type') === 'sitemap')
                        ->required(fn ($get) => $get('crawler_type') === 'sitemap'),

                    TextInput::make('crawl_start_url')
                        ->label('クロール開始URL（一覧ページ）')
                        ->placeholder('https://example.com/articles')
                        ->url()
                        ->required(fn ($get) => $get('crawler_type') === 'html')
                        ->visible(fn ($get) => $get('crawler_type') === 'html'),
                    TextInput::make('list_item_selector')
                        ->label('記事ブロック（リスト）のCSSセレクタ')
                        ->placeholder('例: .article-list .item:not(.is-pr)')
                        ->nullable()
                        ->visible(fn ($get) => $get('crawler_type') === 'html'),
                    TextInput::make('link_selector')
                        ->label('記事リンクのセレクタ')
                        ->placeholder('例: a.main-link')
                        ->visible(fn ($get) => $get('crawler_type') === 'html'),
                    // title_selector, thumbnail_selector, date_selector are hidden/obsolete
                    // because ProcessArticleJob perfectly fetches them inside the article page.
                    TextInput::make('next_page_selector')
                        ->label('「次のページ」へのリンクセレクタ')
                        ->placeholder('例: .pager .next a')
                        ->visible(fn ($get) => $get('crawler_type') === 'html'),
                    TextInput::make('pagination_url_template')
                        ->label('ページネーションURLテンプレート')
                        ->placeholder('https://example.com/news/page/{page}')
                        ->helperText('2ページ目以降のURLの規則を入力してください。ページ番号が入る部分は `{page}` と記述します。（例：`https://example.com/news/page/{page}` や `https://example.com/news?p={page}`）')
                        ->nullable(),
                    TagsInput::make('ng_url_keywords')
                        ->label('除外するURLキーワード（NGワード）')
                        ->helperText('URLにこの文字列が含まれる記事を抽出から除外します（例: osusume, pr=1）'),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('app.name')->label('アプリ')->sortable()->badge()->color('info'),
                TextColumn::make('name')->label('サイト名')->searchable(),
                TextColumn::make('url')->label('URL')->searchable()->limit(30),
                \Filament\Tables\Columns\ToggleColumn::make('is_active')->label('ステータス'),
                TextColumn::make('created_at')->label('作成日')->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')->label('更新日')->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([])
            ->actions([
                \Filament\Actions\Action::make('test_crawl')
                    ->label('クローラ抽出テスト')
                    ->icon('heroicon-o-bug-ant')
                    ->color('warning')
                    ->action(function (\App\Models\Site $record) {
                        try {
                            if ($record->crawler_type === 'sitemap') {
                                $url = $record->sitemap_url;
                                if (empty($url)) throw new \Exception('サイトマップURLが設定されていません。');
                                
                                $response = \Illuminate\Support\Facades\Http::withHeaders([
                                    'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36'
                                ])->timeout(10)->get($url);
                                if (!$response->successful()) throw new \Exception("HTTP通信に失敗: " . $response->status());
                                
                                $xml = simplexml_load_string($response->body());
                                if ($xml === false) throw new \Exception('XMLのパースに失敗しました。');
                                
                                if (isset($xml->sitemap)) {
                                    $sitemaps = $xml->sitemap;
                                    $targetSitemapUrl = (string)$sitemaps[count($sitemaps) - 1]->loc;
                                    
                                    $response = \Illuminate\Support\Facades\Http::withHeaders([
                                        'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36'
                                    ])->timeout(10)->get($targetSitemapUrl);
                                    
                                    if (!$response->successful()) throw new \Exception("子サイトマップのHTTP通信に失敗: " . $response->status());
                                    
                                    $xml = simplexml_load_string($response->body());
                                    if ($xml === false) throw new \Exception('子サイトマップのXMLパースに失敗しました。');
                                }

                                $urls = [];
                                $allUrls = $xml->url ?? [];
                                foreach ($allUrls as $urlEntry) {
                                    $urls[] = (string)$urlEntry->loc;
                                }

                                $ngKeywords = $record->ng_url_keywords ?? [];
                                $siteUrl = rtrim($record->url, '/');
                                $startUrl = rtrim($record->crawl_start_url ?? '', '/');

                                $requiredSubstring = null;
                                if (!empty($record->link_selector) && preg_match('/href\*?=[\'"]([^\'"]+)[\'"]/', $record->link_selector, $matches)) {
                                    $requiredSubstring = $matches[1];
                                }

                                $urls = collect($urls)
                                    ->filter(function ($url) use ($requiredSubstring) {
                                        if ($requiredSubstring && !str_contains($url, $requiredSubstring)) {
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
                                if (empty($url)) throw new \Exception('クロール開始URLが設定されていません。');
                                
                                $response = \Illuminate\Support\Facades\Http::withHeaders([
                                    'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36'
                                ])->timeout(10)->get($url);
                                
                                if (!$response->successful()) throw new \Exception("HTTP通信に失敗: " . $response->status());
                                
                                $crawler = new \Symfony\Component\DomCrawler\Crawler($response->body(), $url);
                                
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
                                            if ($linkUrl) $urls[] = $linkUrl;
                                        } catch (\Exception $e) {}
                                    });
                                } else {
                                    $items = $crawler->filter($listSelector);
                                    $items->each(function ($node) use (&$urls, $linkSelector) {
                                        try {
                                            $linkUrl = null;
                                            if (!empty($linkSelector) && $node->filter($linkSelector)->count() > 0) {
                                                $linkUrl = $node->filter($linkSelector)->first()->link()->getUri();
                                            } elseif ($node->nodeName() === 'a') {
                                                $linkUrl = $node->link()->getUri();
                                            } elseif ($node->filter('a')->count() > 0) {
                                                $linkUrl = $node->filter('a')->first()->link()->getUri();
                                            }
                                            if ($linkUrl) $urls[] = $linkUrl;
                                        } catch (\Exception $e) {}
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
                                if (!empty($linkSelector) && preg_match('/href\*?=[\'"]([^\'"]+)[\'"]/', $linkSelector, $matches)) {
                                    $requiredSubstring = $matches[1];
                                }

                                $urls = collect($urls)
                                    ->filter(function ($url) use ($requiredSubstring) {
                                        if ($requiredSubstring && !str_contains($url, $requiredSubstring)) {
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
                                ->map(fn($u, $index) => ($index + 1) . '. <a href="'.$u.'" target="_blank" style="color:#3b82f6;">'.$u.'</a>')
                                ->implode('<br>');

                            $sampleOutput = '';
                            $samples = array_slice($urls, 0, 3);
                            if (count($samples) > 0) {
                                $sampleOutput .= "<strong>【サンプル抽出テスト (最初の3件)】</strong><br>";
                                $sampleOutput .= "<div style='max-height: 350px; overflow-y: auto; background-color: rgba(255,255,255,0.1); padding: 10px; border-radius: 6px; margin-bottom: 10px;'>";
                                foreach ($samples as $index => $u) {
                                    $num = $index + 1;
                                    $title = '未取得';
                                    $imgUrl = '未取得';
                                    $date = '未取得';
                                    try {
                                        $res = \Illuminate\Support\Facades\Http::withHeaders([
                                            'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36'
                                        ])->timeout(10)->get($u);
                                        if ($res->successful()) {
                                            $sampleCrawler = new \Symfony\Component\DomCrawler\Crawler($res->body(), $u);
                                            if ($sampleCrawler->filter('meta[property="og:title"]')->count() > 0) {
                                                $title = $sampleCrawler->filter('meta[property="og:title"]')->attr('content');
                                            } elseif ($sampleCrawler->filter('title')->count() > 0) {
                                                $title = $sampleCrawler->filter('title')->text();
                                            }
                                            if ($sampleCrawler->filter('meta[property="og:image"]')->count() > 0) {
                                                $imgUrl = $sampleCrawler->filter('meta[property="og:image"]')->attr('content');
                                            } elseif ($sampleCrawler->filter('meta[name="twitter:image"]')->count() > 0) {
                                                $imgUrl = $sampleCrawler->filter('meta[name="twitter:image"]')->attr('content');
                                            } elseif ($sampleCrawler->filter('img')->count() > 0) {
                                                $imgUrl = $sampleCrawler->filter('img')->first()->image()->getUri();
                                            }
                                            if ($sampleCrawler->filter('meta[property="article:published_time"]')->count() > 0) {
                                                $date = $sampleCrawler->filter('meta[property="article:published_time"]')->attr('content');
                                            } elseif ($sampleCrawler->filter('time')->count() > 0) {
                                                $date = $sampleCrawler->filter('time')->first()->attr('datetime') ?? $sampleCrawler->filter('time')->first()->text();
                                            } elseif ($sampleCrawler->filter('[class*="date"]')->count() > 0) {
                                                $date = $sampleCrawler->filter('[class*="date"]')->first()->text();
                                            }
                                        } else {
                                            $title = '取得失敗(HTTP ' . $res->status() . ')';
                                        }
                                    } catch (\Exception $e) {
                                        $title = '取得失敗(' . $e->getMessage() . ')';
                                    }
                                    
                                    $sampleOutput .= "<strong>{$num}件目:</strong><br>";
                                    $sampleOutput .= "【タイトル】 " . htmlspecialchars($title) . "<br>";
                                    $sampleOutput .= "【記事URL】 <a href='{$u}' target='_blank' style='color:#3b82f6;'>{$u}</a><br>";
                                    $sampleOutput .= "【画像URL】 " . htmlspecialchars($imgUrl) . "<br>";
                                    $sampleOutput .= "【投稿日】 " . htmlspecialchars($date) . "<br>";
                                    if ($index < count($samples) - 1) {
                                        $sampleOutput .= "<hr style='margin: 8px 0; border-top: 1px solid rgba(128,128,128,0.3);'>";
                                    }
                                }
                                $sampleOutput .= "</div>";
                            }

                            $nextUrlHtml = isset($nextUrl) ? "<strong>【次へボタンURL】</strong><br>{$nextUrl}<br><br>" : "";

                            $htmlBody = new \Illuminate\Support\HtmlString(
                                $nextUrlHtml .
                                $sampleOutput .
                                "<strong>【取得URL（全{$count}件）】</strong><br>" .
                                "<div style='max-height: 200px; overflow-y: auto; padding: 10px; background-color: rgba(128,128,128,0.1); border-radius: 6px; font-size: 0.85em; white-space: nowrap;'>" .
                                $previewText .
                                "</div>"
                            );

                            $titleText = "URL抽出テストに成功しました 【抽出件数】 {$totalCount}件";

                            \Filament\Notifications\Notification::make()
                                ->success()
                                ->title($titleText)
                                ->body($htmlBody)
                                ->persistent()
                                ->send();
                        } catch (\Exception $e) {
                            \Filament\Notifications\Notification::make()
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
                \Filament\Actions\Action::make('fetch_past_articles')
                    ->label('過去記事の一括取得')
                    ->icon('heroicon-o-rocket-launch')
                    ->color('warning')
                    ->form([
                        \Filament\Forms\Components\Select::make('limit')
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
                    ->action(function (\App\Models\Site $record, array $data) {
                        \App\Jobs\FetchSitePastArticlesJob::dispatch($record, (int) $data['limit']);
                        \Filament\Notifications\Notification::make()
                            ->success()
                            ->title('バックグラウンドで一括取得を開始しました')
                            ->send();
                    }),
                EditAction::make()
                    ->modalWidth('4xl')
                    ->modalHeading('サイト情報の編集')
                    ->modalDescription('サイトの登録情報を変更します。')
                    ->modalSubmitActionLabel('更新する'),
            ])
            ->bulkActions([
                BulkActionGroup::make([DeleteBulkAction::make()]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ManageSites::route('/'),
        ];
    }
}
