<?php

declare(strict_types=1);

namespace App\Services;

use App\DTOs\ScrapedArticleData;
use App\Models\Site;
use App\Support\DateParser;
use Exception;
use Illuminate\Support\Facades\Log;

class ArticleMetadataResolverService
{
    /**
     * @param  array<string, mixed>  $rawMetaData
     */
    public function resolve(
        ArticleScraperService $scraper,
        string $url,
        array $rawMetaData,
        Site $site,
        string $logPrefix = '[ArticleMetadataResolverService]',
    ): ScrapedArticleData {
        $title = $rawMetaData['raw_title'] ?? null;
        $thumbnailUrl = $rawMetaData['thumbnail_url'] ?? null;
        $rawPublishedAt = $rawMetaData['published_at'] ?? null;
        $publishedAt = $rawPublishedAt ? DateParser::parse($rawPublishedAt)?->toDateTimeString() : null;

        if (empty($title) || empty($thumbnailUrl) || empty($publishedAt)) {
            try {
                Log::info("{$logPrefix} 不足データ(title/thumbnail/date)の補完のためHTMLスクレイピングを開始します");

                $siteNgImages = $site->ng_image_urls ?? [];
                $scrapeResult = $scraper->scrape($url, $site->date_selector ?? null, $siteNgImages);

                if ($scrapeResult->success) {
                    $title = empty($title) && ! empty($scrapeResult->title) ? $scrapeResult->title : $title;
                    $thumbnailUrl = empty($thumbnailUrl) && ! empty($scrapeResult->image) ? $scrapeResult->image : $thumbnailUrl;
                    $publishedAt = empty($publishedAt) && ! empty($scrapeResult->date) ? $scrapeResult->date : $publishedAt;
                } else {
                    Log::warning("{$logPrefix} スクレイピング補完に失敗しました: ".($scrapeResult->errorMessage ?? '不明なエラー'));
                }
            } catch (Exception $e) {
                Log::error("{$logPrefix} Failed to fetch/parse metadata for URL {$url} - ".$e->getMessage());
            }
        }

        return new ScrapedArticleData(
            url: $url,
            title: $title,
            image: $thumbnailUrl,
            date: $publishedAt ?: now()->toDateTimeString(),
            success: true
        );
    }
}
