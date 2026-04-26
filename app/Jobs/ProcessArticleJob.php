<?php

declare(strict_types=1);

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Bus;

class ProcessArticleJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public readonly int $siteId,
        public readonly string $url,
        public readonly array $metaData = [],
        public readonly ?string $fetchSource = null
    ) {}

    public function handle(): void
    {
        Bus::chain([
            new ScrapeArticleJob($this->siteId, $this->url, $this->metaData),
            new AnalyzeArticleAiJob($this->siteId, $this->url),
            new PublishArticleJob($this->siteId, $this->url, $this->fetchSource),
        ])->dispatch();
    }
}
