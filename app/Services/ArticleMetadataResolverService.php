<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Site;
use App\Support\DateParser;
use Exception;
use Illuminate\Support\Facades\Log;

class ArticleMetadataResolverService
{
    /**
     * @param  array<string, mixed>  $rawMetaData
     * @return array{title: string|null, image: string|null, date: string}
     */
    public function resolve(
        ArticleScraperService $scraper,
        string $url,
        array $rawMetaData,
        Site $site,
        string $logPrefix = '[ArticleMetadataResolverService]',
    ): array {
        $title = $rawMetaData['raw_title'] ?? null;
        $thumbnailUrl = $rawMetaData['thumbnail_url'] ?? null;
        $rawPublishedAt = $rawMetaData['published_at'] ?? null;
        $publishedAt = $rawPublishedAt ? DateParser::parse($rawPublishedAt)?->toDateTimeString() : null;

        if (empty($title) || empty($thumbnailUrl) || empty($publishedAt)) {
            try {
                Log::info("{$logPrefix} 不足データ(title/thumbnail/date)の補完のためHTMLスクレイピングを開始します");

                $siteNgImages = $site->ng_image_urls ?? [];
                $scrapeResult = $scraper->scrape($url, $site->date_selector ?? null, $siteNgImages);

                if ($scrapeResult['success']) {
                    $title = empty($title) && ! empty($scrapeResult['data']['title']) ? $scrapeResult['data']['title'] : $title;
                    $thumbnailUrl = empty($thumbnailUrl) && ! empty($scrapeResult['data']['image']) ? $scrapeResult['data']['image'] : $thumbnailUrl;
                    $publishedAt = empty($publishedAt) && ! empty($scrapeResult['data']['date']) ? $scrapeResult['data']['date'] : $publishedAt;
                } else {
                    Log::warning("{$logPrefix} スクレイピング補完に失敗しました: ".($scrapeResult['error_message'] ?? '不明なエラー'));
                }
            } catch (Exception $e) {
                Log::error("{$logPrefix} Failed to fetch/parse metadata for URL {$url} - ".$e->getMessage());
            }
        }

        return [
            'title' => $title,
            'image' => $thumbnailUrl,
            'date' => $publishedAt ?: now()->toDateTimeString(),
        ];
    }
}
