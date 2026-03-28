<?php

namespace App\Services;

use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class Crawl4AiService
{
    private string $baseUrl;
    private string $token;

    public function __construct()
    {
        $this->baseUrl = rtrim(config('services.crawl4ai.url', env('CRAWL4AI_URL', '')), '/');
        $this->token   = config('services.crawl4ai.token', env('CRAWL4AI_TOKEN', ''));
    }

    /**
     * 指定URLの記事をCrawl4AI APIで取得し、Markdown本文とサムネイルURLを返します。
     *
     * @return array{markdown: string, thumbnail_url: string|null}
     *
     * @throws RuntimeException
     */
    public function crawl(string $url): array
    {
        if (empty($this->baseUrl)) {
            throw new RuntimeException('CRAWL4AI_URL is not configured.');
        }

        try {
            $response = Http::withToken($this->token)
                ->timeout(60)
                ->post("{$this->baseUrl}/crawl", [
                    'urls'        => [$url],
                    'priority'    => 10,
                    'extra_params' => [
                        'word_count_threshold' => 10,
                        'extract_metadata'     => true,
                    ],
                ]);

            if ($response->failed()) {
                throw new RuntimeException(
                    "Crawl4AI request failed: HTTP {$response->status()} for URL [{$url}]"
                );
            }

            $body = $response->json();
        } catch (RequestException $e) {
            throw new RuntimeException(
                "Crawl4AI HTTP error for URL [{$url}]: {$e->getMessage()}",
                previous: $e
            );
        }

        // レスポンス構造: { results: [{ markdown: "...", metadata: { og_image: "..." } }] }
        $result = $body['results'][0] ?? $body[0] ?? null;

        if (! $result) {
            throw new RuntimeException("Crawl4AI returned empty results for URL [{$url}]");
        }

        $markdown     = $result['markdown'] ?? $result['markdown_v2']['raw_markdown'] ?? '';
        $thumbnailUrl = $this->extractThumbnailUrl($result);

        return [
            'markdown'      => $markdown,
            'thumbnail_url' => $thumbnailUrl,
        ];
    }

    /**
     * クロール結果のメタデータからメイン画像URLを抽出します。
     */
    private function extractThumbnailUrl(array $result): ?string
    {
        $metadata = $result['metadata'] ?? [];

        // og:image を最優先
        if (! empty($metadata['og_image'])) {
            return $metadata['og_image'];
        }

        // twitter:image
        if (! empty($metadata['twitter_image'])) {
            return $metadata['twitter_image'];
        }

        // メタdata内の images 配列の先頭
        if (! empty($metadata['images']) && is_array($metadata['images'])) {
            return $metadata['images'][0] ?? null;
        }

        // トップレベルの media 配列
        if (! empty($result['media']['images']) && is_array($result['media']['images'])) {
            return $result['media']['images'][0]['src'] ?? null;
        }

        return null;
    }
}
