<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Actions\CleanArticleTitleAction;
use App\Jobs\ProcessArticleBatchJob;
use App\Models\App as AppModel;
use App\Models\Article;
use App\Models\Category;
use App\Models\Site;
use App\Services\ArticleAiService;
use App\Services\ArticleScraperService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class ProcessArticleBatchJobTest extends TestCase
{
    use RefreshDatabase;

    // =========================================================================
    // バルク一時停止チェックのテスト
    // =========================================================================

    public function test_job_releases_when_bulk_paused(): void
    {
        Cache::put('is_bulk_paused', true);

        $job = new ProcessArticleBatchJob(
            siteId: 999,
            articles: [],
        );

        $job->handle(
            app(ArticleAiService::class),
            app(ArticleScraperService::class),
            app(CleanArticleTitleAction::class),
        );

        $this->assertDatabaseCount('articles', 0);
    }

    // =========================================================================
    // Site が見つからない場合のテスト
    // =========================================================================

    public function test_job_logs_warning_when_site_not_found(): void
    {
        Log::spy();

        $job = new ProcessArticleBatchJob(
            siteId: 9999,
            articles: [['url' => 'https://example.com/article', 'metaData' => []]],
        );

        $job->handle(
            app(ArticleAiService::class),
            app(ArticleScraperService::class),
            app(CleanArticleTitleAction::class),
        );

        $this->assertDatabaseCount('articles', 0);
        Log::shouldHaveReceived('warning')->once()->with(
            \Mockery::pattern('/Site ID 9999/')
        );
    }

    // =========================================================================
    // 正常系：記事が保存されることを確認
    // =========================================================================

    public function test_job_saves_articles_from_batch_ai_response(): void
    {
        /** @var AppModel $appModel */
        $appModel = AppModel::factory()->create();
        /** @var Category $category */
        $category = Category::factory()->for($appModel)->create();
        /** @var Site $site */
        $site = Site::factory()->for($appModel)->create();

        Http::preventStrayRequests();
        Log::spy();
        Http::fake([
            'https://ollama.unicorn.tokyo:11434/api/generate' => Http::response([
                'response' => json_encode([
                    'results' => [
                        ['article_id' => 1, 'rewritten_title' => 'AIリライトタイトル', 'category_id' => $category->id],
                    ],
                ], JSON_UNESCAPED_UNICODE),
            ]),
        ]);

        $job = new ProcessArticleBatchJob(
            siteId: $site->id,
            articles: [
                [
                    'url' => 'https://example.com/article-1',
                    'metaData' => ['raw_title' => '元のタイトルです長めに', 'thumbnail_url' => null, 'published_at' => null],
                ],
            ],
        );

        $job->handle(
            app(ArticleAiService::class),
            app(ArticleScraperService::class),
            app(CleanArticleTitleAction::class),
        );

        $this->assertDatabaseHas('articles', [
            'url' => 'https://example.com/article-1',
            'title' => 'AIリライトタイトル',
            'site_id' => $site->id,
            'category_id' => $category->id,
        ]);

        $expectedMessage = sprintf(
            '保存完了:| リライト前 %s | リライト後: %s | カテゴリID: %d(%s) | %s |',
            '元のタイトルです長めに',
            'AIリライトタイトル',
            $category->id,
            $category->name,
            'https://example.com/article-1',
        );

        Log::shouldHaveReceived('info')
            ->withArgs(function (string $message) use ($expectedMessage): bool {
                return $message === $expectedMessage;
            })
            ->once();
    }

    // =========================================================================
    // 重複URLのスキップテスト
    // =========================================================================

    public function test_job_skips_already_existing_urls(): void
    {
        /** @var AppModel $appModel */
        $appModel = AppModel::factory()->create();
        /** @var Category $category */
        $category = Category::factory()->for($appModel)->create();
        /** @var Site $site */
        $site = Site::factory()->for($appModel)->create();

        // 既存の記事を作成
        Article::factory()
            ->for($appModel)
            ->for($site)
            ->for($category)
            ->create(['url' => 'https://example.com/existing-article']);

        $job = new ProcessArticleBatchJob(
            siteId: $site->id,
            articles: [
                ['url' => 'https://example.com/existing-article', 'metaData' => []],
            ],
        );

        $job->handle(
            app(ArticleAiService::class),
            app(ArticleScraperService::class),
            app(CleanArticleTitleAction::class),
        );

        // 重複のため新しい記事は追加されない
        $this->assertDatabaseCount('articles', 1);
    }

    // =========================================================================
    // AI結果が部分的な場合のフォールバックテスト
    // =========================================================================

    public function test_job_falls_back_for_articles_missing_from_ai_response(): void
    {
        /** @var AppModel $appModel */
        $appModel = AppModel::factory()->create();
        /** @var Category $category */
        $category = Category::factory()->for($appModel)->create();
        /** @var Site $site */
        $site = Site::factory()->for($appModel)->create();

        Http::preventStrayRequests();
        // AI は article_id=1 のみ返し、2 はサービス側フォールバックへ
        Http::fake([
            'https://ollama.unicorn.tokyo:11434/api/generate' => Http::response([
                'response' => json_encode([
                    'results' => [
                        ['article_id' => 1, 'rewritten_title' => 'タイトル1', 'category_id' => $category->id],
                    ],
                ], JSON_UNESCAPED_UNICODE),
            ]),
        ]);

        Log::spy();

        $job = new ProcessArticleBatchJob(
            siteId: $site->id,
            articles: [
                ['url' => 'https://example.com/article-1', 'metaData' => ['raw_title' => '元タイトル1元タイトル1', 'thumbnail_url' => null, 'published_at' => null]],
                ['url' => 'https://example.com/article-2', 'metaData' => ['raw_title' => '元タイトル2元タイトル2', 'thumbnail_url' => null, 'published_at' => null]],
            ],
        );

        $job->handle(
            app(ArticleAiService::class),
            app(ArticleScraperService::class),
            app(CleanArticleTitleAction::class),
        );

        // article_id=1 はAI結果で保存される
        $this->assertDatabaseHas('articles', ['url' => 'https://example.com/article-1']);
        // article_id=2 はフォールバックで保存される
        $this->assertDatabaseHas('articles', [
            'url' => 'https://example.com/article-2',
            'title' => '元タイトル2元タイトル2',
            'category_id' => $category->id,
        ]);

        Log::shouldHaveReceived('warning')->atLeast()->once();
    }
}
