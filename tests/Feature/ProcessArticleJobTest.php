<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\AnalyzeArticleAiJob;
use App\Jobs\ProcessArticleJob;
use App\Jobs\PublishArticleJob;
use App\Jobs\ScrapeArticleJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class ProcessArticleJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_job_dispatches_chain(): void
    {
        Bus::fake();

        $job = new ProcessArticleJob(
            siteId: 1,
            url: 'https://example.com/articles/123',
            metaData: ['title' => 'テスト記事'],
            fetchSource: 'rss'
        );

        $job->handle();

        Bus::assertChained([
            new ScrapeArticleJob(1, 'https://example.com/articles/123', ['title' => 'テスト記事']),
            new AnalyzeArticleAiJob(1, 'https://example.com/articles/123'),
            new PublishArticleJob(1, 'https://example.com/articles/123', 'rss'),
        ]);
    }
}
