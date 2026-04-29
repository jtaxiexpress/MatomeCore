<?php

declare(strict_types=1);

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Throwable;

class CrawlSiteJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public readonly int $siteId
    ) {
        $this->onQueue('scraping');
    }

    public function handle(): void
    {
        try {
            Redis::funnel('crawl:'.$this->siteId)->limit(1)->then(function () {
                Artisan::call('app:crawl-site', ['site_id' => $this->siteId]);
            }, function () {
                $this->release(5);
            });
        } catch (Throwable $e) {
            Log::error("CrawlSiteJob: Site {$this->siteId} failed - ".$e->getMessage());
            throw $e;
        }
    }
}
