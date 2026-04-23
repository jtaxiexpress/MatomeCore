<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\DateParser;
use Exception;
use Illuminate\Support\Facades\Log;
use Symfony\Component\DomCrawler\Crawler;

class ArticleScraperService
{
    public function __construct(
        private readonly CrawlHttpClient $crawlHttpClient,
    ) {}

    /**
     * システム全体で共通の除外画像URL（デフォルトのサービスアイコン等）
     * これらはサムネイルとして不適切なためAPIコール前にフィルタリングする
     */
    private const GLOBAL_NG_IMAGES = [
        'https://parts.blog.livedoor.jp/img/usr/cmn/ogp_image/livedoor.png',
        'https://stat100.ameba.jp/common_style/img/ogp/ameba_ogp.png',
    ];

    /**
     * URLからHTMLをフェッチし、タイトル・画像・日付のメタデータを抽出します。
     *
     * @param  string  $url  取得対象の記事URL
     * @param  string|null  $siteDateSelector  (任意) サイト設定の固有の日付セレクタ
     * @return array{success: bool, data: array{title: ?string, url: string, date: ?string, image: ?string}, error_message: ?string}
     */
    /**
     * @param  array<string>  $siteNgImages  サイト固有の除外画像URLリスト（管理画面から設定）
     */
    public function scrape(string $url, ?string $siteDateSelector = null, array $siteNgImages = []): array
    {
        $result = [
            'success' => false,
            'data' => [
                'title' => null,
                'url' => $url,
                'date' => null,
                'image' => null,
            ],
            'error_message' => null,
        ];

        try {
            $response = $this->crawlHttpClient->get(
                url: $url,
                headers: [
                    'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8',
                    'Accept-Language' => 'ja,en-US;q=0.9,en;q=0.8',
                ],
                timeoutSeconds: 10,
                options: [
                    'verify' => false,
                    'allow_redirects' => true,
                ],
            );

            if (! $response->successful()) {
                $result['error_message'] = 'HTTP通信失敗 ('.$response->status().')';

                return $result;
            }

            $crawler = new Crawler($response->body(), $url);

            // グローバルNGリストとサイト固有NGリストをマージして使用
            $mergedNgImages = array_filter(array_unique(array_merge(self::GLOBAL_NG_IMAGES, $siteNgImages)));

            $result['data']['title'] = $this->extractTitle($crawler);
            $result['data']['image'] = $this->extractImage($crawler, $mergedNgImages);
            $result['data']['date'] = $this->extractDate($crawler, $siteDateSelector);
            $result['success'] = true;

            $result['error_message'] = $this->buildErrorMessage($result['data']);

        } catch (Exception $e) {
            $result['error_message'] = '通信中/パース中のエラー: '.$e->getMessage();
            Log::warning('ArticleScraperService: '.$e->getMessage()." [URL: {$url}]");
        }

        return $result;
    }

    private function extractTitle(Crawler $crawler): ?string
    {
        if ($crawler->filter('meta[property="og:title"]')->count() > 0) {
            return trim((string) $crawler->filter('meta[property="og:title"]')->attr('content')) ?: null;
        }

        if ($crawler->filter('title')->count() > 0) {
            return trim((string) $crawler->filter('title')->text()) ?: null;
        }

        return null;
    }

    /**
     * @param  array<string>  $ngImages  除外すべき画像URLのリスト（完全一致）
     */
    private function extractImage(Crawler $crawler, array $ngImages = []): ?string
    {
        $imgSelectors = [
            ['selector' => 'meta[property="og:image"]', 'attr' => 'content'],
            ['selector' => 'meta[name="twitter:image"]', 'attr' => 'content'],
            ['selector' => 'article img', 'attr' => 'src'],
            ['selector' => '.entry-content img', 'attr' => 'src'],
            ['selector' => 'img', 'attr' => 'src'],
        ];

        foreach ($imgSelectors as $img) {
            if ($crawler->filter($img['selector'])->count() === 0) {
                continue;
            }

            try {
                if ($img['attr'] === 'src' && $crawler->filter($img['selector'])->first()->nodeName() === 'img') {
                    $src = $crawler->filter($img['selector'])->first()->image()->getUri();
                } else {
                    $src = $crawler->filter($img['selector'])->first()->attr($img['attr']);
                }

                $trimmedSrc = trim((string) $src);
                if (! empty($trimmedSrc) && ! in_array($trimmedSrc, $ngImages, true)) {
                    return $trimmedSrc;
                }
                // NGリストに含まれる場合は次の候補へ
                if (! empty($trimmedSrc)) {
                    Log::debug("[ArticleScraperService] NG画像のためスキップ: {$trimmedSrc}");
                }
            } catch (Exception) {
                // ignore image parsing errors
            }
        }

        return null;
    }

    private function extractDate(Crawler $crawler, ?string $siteDateSelector): ?string
    {
        $dateSelectors = [];
        if (! empty($siteDateSelector)) {
            $dateSelectors[] = ['selector' => $siteDateSelector, 'attr' => '_text'];
        }
        $dateSelectors = array_merge($dateSelectors, [
            ['selector' => 'meta[property="article:published_time"]', 'attr' => 'content'],
            ['selector' => 'time', 'attr' => 'datetime'],
            ['selector' => 'time', 'attr' => '_text'],
            ['selector' => '[class*="date"]', 'attr' => '_text'],
            ['selector' => '[class*="time"]', 'attr' => '_text'],
        ]);

        foreach ($dateSelectors as $dateInfo) {
            if ($crawler->filter($dateInfo['selector'])->count() > 0) {
                $val = $dateInfo['attr'] === '_text'
                    ? $crawler->filter($dateInfo['selector'])->first()->text()
                    : $crawler->filter($dateInfo['selector'])->first()->attr($dateInfo['attr']);

                if (! empty(trim((string) $val))) {
                    return DateParser::parse(trim((string) $val))?->toDateTimeString();
                }
            }
        }

        return null;
    }

    /**
     * @param  array{image: ?string, date: ?string}  $data
     */
    private function buildErrorMessage(array $data): ?string
    {
        if (empty($data['image']) && empty($data['date'])) {
            return '画像・日付タグが見つからず';
        }
        if (empty($data['image'])) {
            return '画像タグ(OGP等)見つからず';
        }
        if (empty($data['date'])) {
            return '日付タグ(OGP/Time等)見つからず';
        }

        return null;
    }
}
