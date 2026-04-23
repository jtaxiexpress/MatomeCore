<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\ArticleClick;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessOutTraffic implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly int $articleId,
        public readonly string $clickedAt
    ) {}

    public function handle(): void
    {
        ArticleClick::create([
            'article_id' => $this->articleId,
            'clicked_at' => $this->clickedAt,
        ]);
    }
}
