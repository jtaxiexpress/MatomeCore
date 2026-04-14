<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Ai\Agents\BatchCategorizeAgent;
use App\Services\ArticleAiService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class ArticleAiServiceBatchTest extends TestCase
{
    private ArticleAiService $service;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::put('ai_prompt_template', 'test template {categories} {title} {articles_json}');
        $this->service = new ArticleAiService;
    }

    // =========================================================================
    // extractBatchJsonResponse のテスト（リフレクションで private メソッドを呼び出す）
    // =========================================================================

    private function callExtractBatchJsonResponse(string $text): array
    {
        $method = new \ReflectionMethod(ArticleAiService::class, 'extractBatchJsonResponse');

        return $method->invoke($this->service, $text);
    }

    public function test_extract_batch_json_response_parses_plain_json_array(): void
    {
        $text = '[{"article_id": 1, "rewritten_title": "タイトル1", "category_id": 3}, {"article_id": 2, "rewritten_title": "タイトル2", "category_id": 5}]';

        $result = $this->callExtractBatchJsonResponse($text);

        $this->assertCount(2, $result);
        $this->assertArrayHasKey(1, $result);
        $this->assertSame(3, $result[1]['category_id']);
        $this->assertSame('タイトル1', $result[1]['rewritten_title']);
        $this->assertArrayHasKey(2, $result);
        $this->assertSame(5, $result[2]['category_id']);
    }

    public function test_extract_batch_json_response_returns_empty_array_when_no_json_found(): void
    {
        $text = 'これはJSONではありません。プレーンテキストです。';

        $result = $this->callExtractBatchJsonResponse($text);

        $this->assertEmpty($result);
    }

    public function test_extract_batch_json_response_returns_empty_array_on_invalid_json(): void
    {
        $text = '[{invalid json}]';

        $result = $this->callExtractBatchJsonResponse($text);

        $this->assertEmpty($result);
    }

    public function test_extract_batch_json_response_skips_items_with_missing_fields(): void
    {
        $text = '[{"article_id": 1, "rewritten_title": "OK", "category_id": 2}, {"article_id": 2, "category_id": 3}]';

        $result = $this->callExtractBatchJsonResponse($text);

        // article_id=2 は rewritten_title がないのでスキップされる
        $this->assertCount(1, $result);
        $this->assertArrayHasKey(1, $result);
        $this->assertArrayNotHasKey(2, $result);
    }

    public function test_extract_batch_json_response_handles_partial_result(): void
    {
        // 3件送っても2件しか返ってこないケース（部分成功）
        $text = '[{"article_id": 1, "rewritten_title": "タイトル1", "category_id": 1}, {"article_id": 3, "rewritten_title": "タイトル3", "category_id": 2}]';

        $result = $this->callExtractBatchJsonResponse($text);

        $this->assertCount(2, $result);
        $this->assertArrayHasKey(1, $result);
        $this->assertArrayNotHasKey(2, $result);
        $this->assertArrayHasKey(3, $result);
    }

    // =========================================================================
    // classifyAndRewriteBatch のバリデーションテスト
    // =========================================================================

    public function test_classify_and_rewrite_batch_throws_when_categories_empty(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->service->classifyAndRewriteBatch(
            articles: [['id' => 1, 'title' => 'テスト']],
            categories: [],
        );
    }

    public function test_classify_and_rewrite_batch_returns_empty_when_articles_empty(): void
    {
        $result = $this->service->classifyAndRewriteBatch(
            articles: [],
            categories: [['id' => 1, 'name' => 'テクノロジー']],
        );

        $this->assertEmpty($result);
    }

    // =========================================================================
    // buildBatchPrompt のテスト
    // =========================================================================

    public function test_build_batch_prompt_contains_categories_and_articles(): void
    {
        $method = new \ReflectionMethod(ArticleAiService::class, 'buildBatchPrompt');

        $articles = [
            ['id' => 1, 'title' => 'テスト記事タイトル1'],
            ['id' => 2, 'title' => 'テスト記事タイトル2'],
        ];
        $categories = [
            ['id' => 10, 'name' => 'テクノロジー'],
            ['id' => 20, 'name' => 'スポーツ'],
        ];

        $prompt = $method->invoke($this->service, $articles, $categories, null);

        $this->assertStringContainsString('テクノロジー (ID: 10)', $prompt);
        $this->assertStringContainsString('スポーツ (ID: 20)', $prompt);
        $this->assertStringContainsString('"article_id":1', $prompt);
        $this->assertStringContainsString('テスト記事タイトル1', $prompt);
        $this->assertStringContainsString('"article_id":2', $prompt);
    }

    public function test_classify_and_rewrite_batch_logs_the_final_prompt_before_sending_to_ai(): void
    {
        Cache::put('ai_base_prompt', 'PROMPT {app_prompt} {categories} {articles_json} {count}');
        Log::spy();

        BatchCategorizeAgent::fake([
            [
                'results' => [
                    [
                        'article_id' => 1,
                        'rewritten_title' => '完成タイトル',
                        'category_id' => 10,
                    ],
                ],
            ],
        ]);

        $result = $this->service->classifyAndRewriteBatch(
            articles: [
                ['id' => 1, 'title' => '元タイトル'],
            ],
            categories: [
                ['id' => 10, 'name' => 'テクノロジー'],
            ],
        );

        $this->assertSame(10, $result[1]['category_id']);
        $this->assertSame('完成タイトル', $result[1]['rewritten_title']);

        Log::shouldHaveReceived('debug')
            ->withArgs(function (string $message): bool {
                return str_contains($message, '[AI] 送信プロンプト:')
                    && str_contains($message, '元タイトル')
                    && str_contains($message, 'テクノロジー');
            })
            ->once();
    }
}
