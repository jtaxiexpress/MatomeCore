<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Actions\CleanArticleTitleAction;
use App\Jobs\ProcessArticleJob;
use App\Models\App as AppModel;
use App\Models\Category;
use App\Models\Site;
use App\Services\ArticleAiService;
use App\Services\ArticleMetadataResolverService;
use App\Services\ArticleScraperService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Cache;
use RuntimeException;
use Tests\TestCase;

class ProcessArticleJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_job_declares_without_overlapping_middleware_with_expected_key(): void
    {
        $job = new ProcessArticleJob(
            siteId: 1,
            url: 'https://example.com/articles/123',
        );

        $middlewares = $job->middleware();

        $this->assertCount(1, $middlewares);
        $this->assertInstanceOf(WithoutOverlapping::class, $middlewares[0]);
        $this->assertSame(md5('https://example.com/articles/123'), $middlewares[0]->key);
        $this->assertSame(60, $middlewares[0]->releaseAfter);
        $this->assertSame(900, $middlewares[0]->expiresAfter);
        $this->assertSame(3, $job->tries);
        $this->assertSame(3, $job->maxExceptions);
    }

    public function test_job_releases_for_transient_ai_exceptions(): void
    {
        Cache::forget('is_bulk_paused');

        $app = AppModel::factory()->create();
        Category::factory()->for($app)->create();
        $site = Site::factory()->for($app)->create();

        $job = new ProcessArticleJob(
            siteId: $site->id,
            url: 'https://example.com/transient-error',
            metaData: ['raw_title' => '十分長い元タイトルです'],
        );
        $job->withFakeQueueInteractions();

        $aiService = $this->createMock(ArticleAiService::class);
        $aiService->expects($this->once())
            ->method('classifyAndRewrite')
            ->willThrowException(new ConnectionException('connection failed'));

        $resolver = $this->createMock(ArticleMetadataResolverService::class);
        $resolver->expects($this->once())
            ->method('resolve')
            ->willReturn([
                'title' => '十分長い元タイトルです',
                'image' => null,
                'date' => now()->toDateTimeString(),
            ]);

        $cleanTitleAction = $this->createMock(CleanArticleTitleAction::class);
        $cleanTitleAction->expects($this->once())
            ->method('execute')
            ->willReturn('十分長い元タイトルです');

        $scraper = $this->createMock(ArticleScraperService::class);

        $job->handle($aiService, $scraper, $cleanTitleAction, $resolver);

        $job->assertReleased(60);
        $job->assertNotFailed();
        $this->assertDatabaseCount('articles', 0);
    }

    public function test_job_fails_for_non_transient_exceptions(): void
    {
        Cache::forget('is_bulk_paused');

        $app = AppModel::factory()->create();
        Category::factory()->for($app)->create();
        $site = Site::factory()->for($app)->create();

        $job = new ProcessArticleJob(
            siteId: $site->id,
            url: 'https://example.com/non-transient-error',
            metaData: ['raw_title' => '十分長い元タイトルです'],
        );
        $job->withFakeQueueInteractions();

        $aiService = $this->createMock(ArticleAiService::class);
        $aiService->expects($this->once())
            ->method('classifyAndRewrite')
            ->willThrowException(new RuntimeException('unprocessable payload'));

        $resolver = $this->createMock(ArticleMetadataResolverService::class);
        $resolver->expects($this->once())
            ->method('resolve')
            ->willReturn([
                'title' => '十分長い元タイトルです',
                'image' => null,
                'date' => now()->toDateTimeString(),
            ]);

        $cleanTitleAction = $this->createMock(CleanArticleTitleAction::class);
        $cleanTitleAction->expects($this->once())
            ->method('execute')
            ->willReturn('十分長い元タイトルです');

        $scraper = $this->createMock(ArticleScraperService::class);

        $job->handle($aiService, $scraper, $cleanTitleAction, $resolver);

        $job->assertFailedWith(RuntimeException::class);
        $job->assertNotReleased();
        $this->assertDatabaseCount('articles', 0);
    }
}
