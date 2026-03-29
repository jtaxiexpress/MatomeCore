<?php

namespace App\Services;

use Carbon\Carbon;
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
     * @return array 抽出結果の構造化データ
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

            // 1. Title fallback
            if ($crawler->filter('meta[property="og:title"]')->count() > 0) {
                $result['data']['title'] = trim((string) $crawler->filter('meta[property="og:title"]')->attr('content'));
            } elseif ($crawler->filter('title')->count() > 0) {
                $result['data']['title'] = trim((string) $crawler->filter('title')->text());
            }

            if (empty($result['data']['title'])) {
                $result['data']['title'] = null;
            }

            // 2. Thumbnail fallback
            $imgSelectors = [
                ['selector' => 'meta[property="og:image"]', 'attr' => 'content'],
                ['selector' => 'meta[name="twitter:image"]', 'attr' => 'content'],
                ['selector' => 'article img', 'attr' => 'src'],
                ['selector' => '.entry-content img', 'attr' => 'src'],
                ['selector' => 'img', 'attr' => 'src'],
            ];

            foreach ($imgSelectors as $img) {
                if ($crawler->filter($img['selector'])->count() > 0) {
                    try {
                        // srcではなく絶対パス(URI)を取得できる場合はそちらを優先
                        if ($img['attr'] === 'src' && $crawler->filter($img['selector'])->first()->nodeName() === 'img') {
                            $src = $crawler->filter($img['selector'])->first()->image()->getUri();
                        } else {
                            $src = $crawler->filter($img['selector'])->first()->attr($img['attr']);
                        }

                        if (! empty(trim((string) $src))) {
                            $result['data']['image'] = trim((string) $src);
                            break;
                        }
                    } catch (Exception $e) {
                        // image()->getUri() 等で不正なURLなどが発生した場合無視して次へ
                    }
                }
            }

            // 3. Published_at fallback
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

            $extractedDateRaw = null;
            foreach ($dateSelectors as $dateInfo) {
                if ($crawler->filter($dateInfo['selector'])->count() > 0) {
                    $val = $dateInfo['attr'] === '_text'
                        ? $crawler->filter($dateInfo['selector'])->first()->text()
                        : $crawler->filter($dateInfo['selector'])->first()->attr($dateInfo['attr']);

                    if (! empty(trim((string) $val))) {
                        $extractedDateRaw = trim((string) $val);
                        break;
                    }
                }
            }

            // 本文からの日付推測（Fallback）
            if (empty($extractedDateRaw) && $crawler->filter('body')->count() > 0) {
                $bodyText = $crawler->filter('body')->text();
                // "YYYY年M月D日 HH:MM:SS" または "YYYY/MM/DD" 形式を抽出
                if (preg_match('/(20\d{2})[年\/\-\s]+(\d{1,2})[月\/\-\s]+(\d{1,2})日?\s*(?:[（\(][日月火水木金土祝][）\)])?\s*(?:(\d{1,2})[:時](\d{1,2})(?::(\d{1,2}))?)?/', $bodyText, $matches)) {
                    $extractedDateRaw = $matches[0];
                }
            }

            if (! empty($extractedDateRaw)) {
                $result['data']['date'] = $this->parseDateString($extractedDateRaw)?->toDateTimeString();
            }

            // 成功フラグの設定（基本的な抽出完了として扱う）
            $result['success'] = true;

            // 部分的な欠損に対するエラーメッセージの付与（テスト時に役立つ情報）
            if (empty($result['data']['image']) && empty($result['data']['date'])) {
                $result['error_message'] = '画像・日付タグが見つからず';
            } elseif (empty($result['data']['image'])) {
                $result['error_message'] = '画像タグ(OGP等)見つからず';
            } elseif (empty($result['data']['date'])) {
                $result['error_message'] = '日付タグ(OGP/Time等)見つからず';
            }

        } catch (Exception $e) {
            $result['error_message'] = '通信中/パース中のエラー: '.$e->getMessage();
            Log::warning('ArticleScraperService: '.$e->getMessage()." [URL: {$url}]");
        }

        return $result;
    }

    /**
     * あらゆる形式の文字列から日時をパースし、安全にCarbonインスタンスを返します。
     * 解析失敗時は null を返します。
     */
    private function parseDateString(?string $rawDate): ?Carbon
    {
        if (empty($rawDate)) {
            return null;
        }

        $cleanedDate = preg_replace('/[（\(][日月火水木金土祝][）\)]/u', '', $rawDate);
        $cleanedDate = trim($cleanedDate);

        try {
            return Carbon::parse($cleanedDate);
        } catch (Exception $e) {
            if (preg_match('/(20\d{2})[年\/\-\s]+(\d{1,2})[月\/\-\s]+(\d{1,2})日?\s*(?:(\d{1,2})[:時](\d{1,2})(?::(\d{1,2}))?)?/', $cleanedDate, $matches)) {
                try {
                    return Carbon::create(
                        (int) $matches[1],
                        (int) $matches[2],
                        (int) $matches[3],
                        (int) ($matches[4] ?? 0),
                        (int) ($matches[5] ?? 0),
                        (int) ($matches[6] ?? 0)
                    );
                } catch (Exception $e2) {
                    return null;
                }
            }
        }

        return null; // 意図的に現在時刻はセットしない。呼び出し側が判断するため。
    }
}
