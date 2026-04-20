<?php

declare(strict_types=1);

namespace App\Services\Crawlers;

use App\Models\Site;
use App\Services\CrawlHttpClient;
use Exception;
use Illuminate\Support\Facades\Log;
use Symfony\Component\DomCrawler\Crawler;

class HtmlCrawler implements CrawlerStrategy
{
    public function __construct(
        private readonly CrawlHttpClient $crawlHttpClient,
    ) {}

    /**
     * @return array<int, array{url: string, title: string|null, thumbnail: string|null, published_at: null}>
     */
    public function crawl(Site $site, int $maxPages = 5): array
    {
        if (empty($site->crawl_start_url) && empty($site->url)) {
            return [];
        }

        if (empty($site->list_item_selector) && empty($site->link_selector)) {
            return [];
        }

        $articles = [];
        $baseUrl = rtrim((string) preg_replace('/\/page\/\d+$/i', '', $site->crawl_start_url ?? $site->url), '/');

        for ($page = 1; $page <= $maxPages; $page++) {
            if (! empty($site->pagination_url_template)) {
                $currentUrl = str_replace('{page}', (string) $page, $site->pagination_url_template);
            } else {
                $currentUrl = $page === 1 ? ($site->crawl_start_url ?? $site->url) : $baseUrl.'/page/'.$page;
            }

            try {
                $response = $this->crawlHttpClient->get(
                    url: $currentUrl,
                    headers: [
                        'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,*/*;q=0.8',
                        'Accept-Language' => 'ja,en-US;q=0.7,en;q=0.3',
                    ],
                    timeoutSeconds: 30,
                );

                if (! $response->successful()) {
                    if ($page === 1 && is_null($site->failing_since)) {
                        $site->update(['failing_since' => now()]);
                    }
                    break;
                }

                if ($page === 1 && ! is_null($site->failing_since)) {
                    $site->update(['failing_since' => null]);
                }

                $crawler = new Crawler($response->body(), $currentUrl);

                if (empty($site->list_item_selector)) {
                    $items = $crawler->filter((string) $site->link_selector);
                } else {
                    $items = $crawler->filter((string) $site->list_item_selector);
                }

                if ($items->count() === 0) {
                    break;
                }

                $items->each(function (Crawler $node) use ($site, &$articles): void {
                    try {
                        $url = null;
                        if (empty($site->list_item_selector)) {
                            if ($node->nodeName() === 'a') {
                                $url = $node->link()->getUri();
                            } elseif ($node->filter('a')->count() > 0) {
                                $url = $node->filter('a')->first()->link()->getUri();
                            }
                        } else {
                            if ($site->link_selector && $node->filter((string) $site->link_selector)->count() > 0) {
                                $url = $node->filter((string) $site->link_selector)->first()->link()->getUri();
                            } elseif ($node->nodeName() === 'a') {
                                $url = $node->link()->getUri();
                            } elseif ($node->filter('a')->count() > 0) {
                                $url = $node->filter('a')->first()->link()->getUri();
                            }
                        }

                        if (! is_string($url) || $url === '') {
                            return;
                        }

                        $articles[] = [
                            'url' => $url,
                            'title' => null,
                            'thumbnail' => null,
                            'published_at' => null,
                        ];
                    } catch (Exception $e) {
                        Log::warning('HtmlCrawler: item parse error', [
                            'site_id' => $site->id,
                            'message' => $e->getMessage(),
                        ]);
                    }
                });

                sleep(2);
            } catch (Exception $e) {
                if ($page === 1 && is_null($site->failing_since)) {
                    $site->update(['failing_since' => now()]);
                }
                break;
            }
        }

        return $articles;
    }
}
