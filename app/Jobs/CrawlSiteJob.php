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
use Throwable;

class CrawlSiteJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public readonly int $siteId
    ) {}

    public function handle(): void
    {
        try {
            Artisan::call('app:crawl-site', ['site_id' => $this->siteId]);
        } catch (Throwable $e) {
            Log::error("CrawlSiteJob: Site {$this->siteId} failed - " . $e->getMessage());
            throw $e;
        }
    }
}
