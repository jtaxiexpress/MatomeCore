<?php

declare(strict_types=1);

namespace App\Services\Crawlers;

use App\Models\Site;
use Carbon\CarbonInterface;

interface CrawlerStrategy
{
    /**
     * @return array<int, array{url: string, title: string|null, thumbnail: string|null, published_at: CarbonInterface|string|null}>
     */
    public function crawl(Site $site, int $maxPages = 5): array;
}
