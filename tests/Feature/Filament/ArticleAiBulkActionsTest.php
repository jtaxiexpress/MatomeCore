<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Filament\Resources\ArticleResource\Pages\ManageArticles;
use App\Models\App as AppModel;
use App\Models\Article;
use App\Models\Category;
use App\Models\Site;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

class ArticleAiBulkActionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::put('ai_prompt_template', '{categories}|{title}');
        Cache::put('ai_base_prompt', 'PROMPT {app_prompt} {categories} {articles_json} {count}');
        Cache::put('ollama_model', 'gemma4:e2b');
        Cache::put('is_bulk_paused', false);
    }

    protected function tearDown(): void
    {
        Filament::setTenant(null, isQuiet: true);

        parent::tearDown();
    }

    public function test_bulk_action_reprocesses_title_and_category_for_selected_articles(): void
    {
        $admin = User::factory()->admin()->create();
        $tenant = AppModel::factory()->create();
        $site = Site::factory()->for($tenant, 'app')->create();
        $sourceCategory = Category::factory()->for($tenant, 'app')->create(['name' => '元カテゴリ']);
        $targetCategory = Category::factory()->for($tenant, 'app')->create(['name' => '再分類先']);
        $firstArticle = Article::factory()->for($tenant, 'app')->for($site, 'site')->for($sourceCategory, 'category')->create([
            'title' => '現在のタイトル1',
            'original_title' => '元の記事1',
        ]);
        $secondArticle = Article::factory()->for($tenant, 'app')->for($site, 'site')->for($sourceCategory, 'category')->create([
            'title' => '現在のタイトル2',
            'original_title' => null,
        ]);

        Filament::setTenant($tenant, isQuiet: true);
        $this->fakeBatchResponse([
            [
                'article_id' => $firstArticle->id,
                'rewritten_title' => 'AIタイトル1',
                'category_id' => $targetCategory->id,
            ],
            [
                'article_id' => $secondArticle->id,
                'rewritten_title' => 'AIタイトル2',
                'category_id' => $targetCategory->id,
            ],
        ]);

        Livewire::actingAs($admin)
            ->test(ManageArticles::class)
            ->selectTableRecords([$firstArticle->id, $secondArticle->id])
            ->callAction(TestAction::make('reprocessSelectedArticles')->table()->bulk())
            ->assertNotified('2件の記事のタイトルとカテゴリを再処理しました');

        Http::assertSentCount(1);
        Http::assertSent(function ($request): bool {
            $data = $request->data();
            $prompt = (string) ($data['prompt'] ?? '');

            return str_ends_with($request->url(), '/api/generate')
                && str_contains($prompt, '元の記事1')
                && str_contains($prompt, '現在のタイトル2')
                && ! str_contains($prompt, '現在のタイトル1');
        });

        $firstArticle->refresh();
        $secondArticle->refresh();

        $this->assertSame('AIタイトル1', $firstArticle->title);
        $this->assertSame($targetCategory->id, $firstArticle->category_id);
        $this->assertSame('AIタイトル2', $secondArticle->title);
        $this->assertSame($targetCategory->id, $secondArticle->category_id);
    }

    public function test_bulk_action_rewrites_titles_only_for_selected_articles(): void
    {
        $admin = User::factory()->admin()->create();
        $tenant = AppModel::factory()->create();
        $site = Site::factory()->for($tenant, 'app')->create();
        $sourceCategory = Category::factory()->for($tenant, 'app')->create(['name' => '元カテゴリ']);
        $targetCategory = Category::factory()->for($tenant, 'app')->create(['name' => '別カテゴリ']);
        $firstArticle = Article::factory()->for($tenant, 'app')->for($site, 'site')->for($sourceCategory, 'category')->create([
            'title' => '現在のタイトル1',
            'original_title' => '元の記事1',
        ]);
        $secondArticle = Article::factory()->for($tenant, 'app')->for($site, 'site')->for($sourceCategory, 'category')->create([
            'title' => '現在のタイトル2',
            'original_title' => '元の記事2',
        ]);

        Filament::setTenant($tenant, isQuiet: true);
        $this->fakeBatchResponse([
            [
                'article_id' => $firstArticle->id,
                'rewritten_title' => '再リライト1',
                'category_id' => $targetCategory->id,
            ],
            [
                'article_id' => $secondArticle->id,
                'rewritten_title' => '再リライト2',
                'category_id' => $targetCategory->id,
            ],
        ]);

        Livewire::actingAs($admin)
            ->test(ManageArticles::class)
            ->selectTableRecords([$firstArticle->id, $secondArticle->id])
            ->callAction(TestAction::make('rewriteSelectedArticleTitles')->table()->bulk())
            ->assertNotified('2件の記事のタイトルを再リライトしました');

        Http::assertSentCount(1);
        Http::assertSent(function ($request): bool {
            $data = $request->data();
            $prompt = (string) ($data['prompt'] ?? '');

            return str_ends_with($request->url(), '/api/generate')
                && str_contains($prompt, '元の記事1')
                && str_contains($prompt, '元の記事2');
        });

        $firstArticle->refresh();
        $secondArticle->refresh();

        $this->assertSame('再リライト1', $firstArticle->title);
        $this->assertSame($sourceCategory->id, $firstArticle->category_id);
        $this->assertSame('再リライト2', $secondArticle->title);
        $this->assertSame($sourceCategory->id, $secondArticle->category_id);
        $this->assertSame('元の記事1', $firstArticle->original_title);
        $this->assertSame('元の記事2', $secondArticle->original_title);
    }

    public function test_bulk_action_reclassifies_categories_only_for_selected_articles(): void
    {
        $admin = User::factory()->admin()->create();
        $tenant = AppModel::factory()->create();
        $site = Site::factory()->for($tenant, 'app')->create();
        $sourceCategory = Category::factory()->for($tenant, 'app')->create(['name' => '元カテゴリ']);
        $targetCategory = Category::factory()->for($tenant, 'app')->create(['name' => '再分類先']);
        $firstArticle = Article::factory()->for($tenant, 'app')->for($site, 'site')->for($sourceCategory, 'category')->create([
            'title' => '現在のタイトル1',
            'original_title' => '元の記事1',
        ]);
        $secondArticle = Article::factory()->for($tenant, 'app')->for($site, 'site')->for($sourceCategory, 'category')->create([
            'title' => '現在のタイトル2',
            'original_title' => null,
        ]);

        Filament::setTenant($tenant, isQuiet: true);
        $this->fakeBatchResponse([
            [
                'article_id' => $firstArticle->id,
                'rewritten_title' => '未使用タイトル1',
                'category_id' => $targetCategory->id,
            ],
            [
                'article_id' => $secondArticle->id,
                'rewritten_title' => '未使用タイトル2',
                'category_id' => $targetCategory->id,
            ],
        ]);

        Livewire::actingAs($admin)
            ->test(ManageArticles::class)
            ->selectTableRecords([$firstArticle->id, $secondArticle->id])
            ->callAction(TestAction::make('reclassifySelectedArticleCategories')->table()->bulk())
            ->assertNotified('2件の記事のカテゴリを再振り分けしました');

        Http::assertSentCount(1);
        Http::assertSent(function ($request): bool {
            $data = $request->data();
            $prompt = (string) ($data['prompt'] ?? '');

            return str_ends_with($request->url(), '/api/generate')
                && str_contains($prompt, '元の記事1')
                && str_contains($prompt, '現在のタイトル2');
        });

        $firstArticle->refresh();
        $secondArticle->refresh();

        $this->assertSame('現在のタイトル1', $firstArticle->title);
        $this->assertSame($targetCategory->id, $firstArticle->category_id);
        $this->assertSame('現在のタイトル2', $secondArticle->title);
        $this->assertSame($targetCategory->id, $secondArticle->category_id);
        $this->assertSame('元の記事1', $firstArticle->original_title);
    }

    public function test_manage_articles_table_does_not_lazy_load_category_or_site_relations(): void
    {
        $admin = User::factory()->admin()->create();
        $tenant = AppModel::factory()->create();
        $site = Site::factory()->for($tenant, 'app')->create();
        $category = Category::factory()->for($tenant, 'app')->create();

        $article = Article::factory()
            ->for($tenant, 'app')
            ->for($site, 'site')
            ->for($category, 'category')
            ->create([
                'title' => '遅延ロード検知テスト',
                'thumbnail_url' => null,
            ]);

        Filament::setTenant($tenant, isQuiet: true);

        Model::preventLazyLoading();
        Model::handleLazyLoadingViolationUsing(static function (Model $model, string $relation): void {
            if ($model instanceof Article && in_array($relation, ['category', 'site'], true)) {
                throw new RuntimeException("Unexpected lazy loading: {$relation}");
            }
        });

        try {
            Livewire::actingAs($admin)
                ->test(ManageArticles::class)
                ->assertCanSeeTableRecords([$article]);

            $this->assertTrue(true);
        } finally {
            Model::preventLazyLoading(false);
            Model::handleLazyLoadingViolationUsing(static function (): void {});
        }
    }

    private function fakeBatchResponse(array $results): void
    {
        Http::preventStrayRequests();

        Http::fake(function ($request) use ($results) {
            if (str_ends_with($request->url(), '/api/generate')) {
                return Http::response([
                    'response' => json_encode([
                        'results' => $results,
                    ], JSON_UNESCAPED_UNICODE),
                ]);
            }

            return Http::response(null, 404);
        });
    }
}
