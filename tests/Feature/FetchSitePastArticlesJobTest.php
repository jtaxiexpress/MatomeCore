<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\FetchSitePastArticlesJob;
use App\Models\App as AppModel;
use App\Models\Article;
use App\Models\Category;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class FetchSitePastArticlesJobTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        \Mockery::close();

        parent::tearDown();
    }

    public function test_chunked_duplicate_check_splits_article_url_queries_into_batches_of_one_thousand(): void
    {
        /** @var AppModel $appModel */
        $appModel = AppModel::factory()->create();
        /** @var Category $category */
        $category = Category::factory()->for($appModel)->create();
        /** @var Site $site */
        $site = Site::factory()->for($appModel)->create();

        Article::factory()->for($appModel)->for($category)->for($site)->create([
            'url' => 'https://example.com/articles/10',
        ]);
        Article::factory()->for($appModel)->for($category)->for($site)->create([
            'url' => 'https://example.com/articles/1010',
        ]);

        $job = new FetchSitePastArticlesJob($site);
        $method = new \ReflectionMethod($job, 'pluckExistingUrlsChunked');
        $method->setAccessible(true);

        $candidateUrls = array_map(
            static fn (int $index): string => "https://example.com/articles/{$index}",
            range(1, 1501),
        );

        DB::flushQueryLog();
        DB::enableQueryLog();

        $existingUrls = $method->invoke($job, $candidateUrls);
        $queries = array_values(array_filter(DB::getQueryLog(), static function (array $query): bool {
            $sql = strtolower($query['query'] ?? '');

            return str_contains($sql, 'select') && str_contains($sql, 'from') && str_contains($sql, 'articles');
        }));

        $this->assertCount(2, $queries);
        $this->assertSame(1000, count($queries[0]['bindings']));
        $this->assertSame(501, count($queries[1]['bindings']));
        $this->assertSame([
            'https://example.com/articles/10',
            'https://example.com/articles/1010',
        ], $existingUrls);
    }

    public function test_job_sends_notification_when_no_new_past_articles_are_found(): void
    {
        /** @var AppModel $appModel */
        $appModel = AppModel::factory()->create();
        /** @var Site $site */
        $site = Site::factory()->for($appModel)->create([
            'crawler_type' => 'sitemap',
            'sitemap_url' => 'https://example.com/sitemap.xml',
        ]);

        $admin = User::factory()->admin()->create();
        $appUser = User::factory()->create();
        $appUser->apps()->attach($appModel);

        Http::preventStrayRequests();
        Http::fake([
            'https://example.com/sitemap.xml' => Http::response(
                '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"></urlset>',
                200,
            ),
        ]);

        $job = new FetchSitePastArticlesJob($site);
        $message = $job->handle();

        $this->assertStringContainsString('新しい記事を 0 件取得', $message);
        $this->assertDatabaseCount('notifications', 2);

        $admin->refresh();
        $appUser->refresh();

        $adminNotification = $admin->notifications()->first();
        $appNotification = $appUser->notifications()->first();

        $this->assertNotNull($adminNotification);
        $this->assertNotNull($appNotification);
        $this->assertSame("{$site->name} - 過去記事一括取得", $adminNotification->data['title']);
        $this->assertSame('fetch_past_sitemap', $adminNotification->data['source']);
        $this->assertStringContainsString('新規記事はありませんでした。', $adminNotification->data['body']);
        $this->assertSame("{$site->name} - 過去記事一括取得", $appNotification->data['title']);
    }
}
