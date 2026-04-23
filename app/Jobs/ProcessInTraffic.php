<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\SiteIn;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessInTraffic implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly int $siteId,
        public readonly string $visitedAt
    ) {}

    public function handle(): void
    {
        SiteIn::create([
            'site_id' => $this->siteId,
            'visited_at' => $this->visitedAt,
        ]);
    }
}
