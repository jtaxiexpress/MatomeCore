<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\DateParser;
use Exception;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Symfony\Component\DomCrawler\Crawler;

class ArticleScraperService
{
    /**
     * URLからHTMLをフェッチし、タイトル・画像・日付のメタデータを抽出します。
     *
     * @param  string  $url  取得対象の記事URL
     * @param  string|null  $siteDateSelector  (任意) サイト設定の固有の日付セレクタ
     * @return array{success: bool, data: array{title: ?string, url: string, date: ?string, image: ?string}, error_message: ?string}
     */
    public function scrape(string $url, ?string $siteDateSelector = null): array
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
            $response = Http::withHeaders([
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8',
                'Accept-Language' => 'ja,en-US;q=0.9,en;q=0.8',
            ])->withOptions([
                'verify' => false,
                'allow_redirects' => true,
            ])->timeout(10)->get($url);

            if (! $response->successful()) {
                $result['error_message'] = 'HTTP通信失敗 ('.$response->status().')';

                return $result;
            }

            $crawler = new Crawler($response->body(), $url);

            $result['data']['title'] = $this->extractTitle($crawler);
            $result['data']['image'] = $this->extractImage($crawler);
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

    private function extractImage(Crawler $crawler): ?string
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

                if (! empty(trim((string) $src))) {
                    return trim((string) $src);
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

        if ($crawler->filter('body')->count() > 0) {
            $bodyText = $crawler->filter('body')->text();
            if (preg_match('/(20\d{2})[年\/\-\s]+(\d{1,2})[月\/\-\s]+(\d{1,2})日?\s*(?:[（\(][日月火水木金土祝][）\)])?\s*(?:(\d{1,2})[:時](\d{1,2})(?::(\d{1,2}))?)?/', $bodyText, $matches)) {
                return DateParser::parse($matches[0])?->toDateTimeString();
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
