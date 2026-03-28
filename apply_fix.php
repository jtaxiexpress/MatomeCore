<?php
$f = __DIR__ . '/app/Filament/Resources/SiteResource.php';
$c = file_get_contents($f);

$s1 = <<< 'SEARCH'
                            TextInput::make('list_item_selector')
                                ->label('記事ブロック（リスト）のCSSセレクタ')
                                ->placeholder('例: .article-list .item:not(.is-pr)')
                                ->required(fn ($get) => $get('crawler_type') === 'html'),
SEARCH;
$r1 = <<< 'REPLACE'
                            TextInput::make('list_item_selector')
                                ->label('記事ブロック（リスト）のCSSセレクタ')
                                ->placeholder('例: .article-list .item:not(.is-pr)')
                                ->nullable(),
REPLACE;
$c = str_replace($s1, $r1, $c);

$s2 = <<< 'SEARCH'
                                $urls = [];
                                $items->each(function ($node) use (&$urls, $linkSelector) {
                                    try {
                                        $linkUrl = null;
                                        if (empty($linkSelector) && $node->nodeName() === 'a') {
                                            $linkUrl = $node->link()->getUri();
                                        } elseif (!empty($linkSelector) && $node->filter($linkSelector)->count() > 0) {
                                            $linkUrl = $node->filter($linkSelector)->first()->link()->getUri();
                                        } elseif ($node->nodeName() === 'a') {
                                            $linkUrl = $node->link()->getUri();
                                        }
                                        if ($linkUrl) $urls[] = $linkUrl;
                                    } catch (\Exception $e) {}
                                });
SEARCH;
$r2 = <<< 'REPLACE'
                                $urls = [];
                                $items->each(function ($node) use (&$urls, $listSelector, $linkSelector) {
                                    try {
                                        $linkUrl = null;
                                        if (empty($listSelector)) {
                                            if ($node->nodeName() === 'a') {
                                                $linkUrl = $node->link()->getUri();
                                            } elseif ($node->filter('a')->count() > 0) {
                                                $linkUrl = $node->filter('a')->first()->link()->getUri();
                                            }
                                        } else {
                                            if (!empty($linkSelector) && $node->filter($linkSelector)->count() > 0) {
                                                $linkUrl = $node->filter($linkSelector)->first()->link()->getUri();
                                            } elseif ($node->nodeName() === 'a') {
                                                $linkUrl = $node->link()->getUri();
                                            } elseif ($node->filter('a')->count() > 0) {
                                                $linkUrl = $node->filter('a')->first()->link()->getUri();
                                            }
                                        }
                                        if ($linkUrl) $urls[] = $linkUrl;
                                    } catch (\Exception $e) {}
                                });
REPLACE;
$c = str_replace($s2, $r2, $c);
file_put_contents($f, $c);
echo "SiteResource.php validation & test_crawl fallback applied.\n";

$f2 = __DIR__ . '/app/Console/Commands/CrawlSiteCommand.php';
$c2 = file_get_contents($f2);

$s3 = <<< 'SEARCH'
        if (empty($currentUrl) || empty($site->list_item_selector)) {
            $this->error('Start URL or List Item Selector is missing for HTML crawl.');
            return;
        }
SEARCH;
$r3 = <<< 'REPLACE'
        if (empty($currentUrl)) {
            $this->error('Start URL is missing for HTML crawl.');
            return;
        }
        if (empty($site->list_item_selector) && empty($site->link_selector)) {
            $this->error('List Item Selector and Link Selector are missing for HTML crawl.');
            return;
        }
REPLACE;
$c2 = str_replace($s3, $r3, $c2);

$s4 = <<< 'SEARCH'
                $crawler = new Crawler($response->body(), $currentUrl);
                $items = $crawler->filter($site->list_item_selector);
                
                if ($items->count() === 0) {
                    $this->info("No items found on this page.");
                    break;
                }

                $items->each(function (Crawler $node) use ($site) {
                    try {
                        // URL
                        $url = null;
                        if ($site->link_selector && $node->filter($site->link_selector)->count() > 0) {
                            $url = $node->filter($site->link_selector)->first()->link()->getUri();
                        } elseif ($node->nodeName() === 'a') {
                            $url = $node->link()->getUri();
                        }
SEARCH;
$r4 = <<< 'REPLACE'
                $crawler = new Crawler($response->body(), $currentUrl);
                
                if (empty($site->list_item_selector)) {
                    $items = $crawler->filter($site->link_selector);
                } else {
                    $items = $crawler->filter($site->list_item_selector);
                }
                
                if ($items->count() === 0) {
                    $this->info("No items found on this page.");
                    break;
                }

                $items->each(function (Crawler $node) use ($site) {
                    try {
                        // URL
                        $url = null;
                        
                        if (empty($site->list_item_selector)) {
                            if ($node->nodeName() === 'a') {
                                $url = $node->link()->getUri();
                            } elseif ($node->filter('a')->count() > 0) {
                                $url = $node->filter('a')->first()->link()->getUri();
                            }
                        } else {
                            if ($site->link_selector && $node->filter($site->link_selector)->count() > 0) {
                                $url = $node->filter($site->link_selector)->first()->link()->getUri();
                            } elseif ($node->nodeName() === 'a') {
                                $url = $node->link()->getUri();
                            } elseif ($node->filter('a')->count() > 0) {
                                $url = $node->filter('a')->first()->link()->getUri();
                            }
                        }
REPLACE;
$c2 = str_replace($s4, $r4, $c2);
file_put_contents($f2, $c2);
echo "CrawlSiteCommand.php fallback applied.\n";
echo "Finished! You can remove this script.\n";
