<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\ArticleAiService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ArticleAiServiceBatchTest extends TestCase
{
    private ArticleAiService $service;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::put('ai_prompt_template', "{categories}\nタイトル:{title}");
        Cache::put('ai_base_prompt', 'PROMPT {app_prompt} {categories} {articles_json} {count}');
        Cache::put('ollama_model', 'gemma4:e2b');
        $this->service = new ArticleAiService;
    }

    public function test_classify_and_rewrite_returns_structured_result_with_ollama_json_format(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://ollama.unicorn.tokyo:11434/api/generate' => Http::response([
                'response' => json_encode([
                    'category_id' => 10,
                    'rewritten_title' => '完成タイトル',
                ], JSON_UNESCAPED_UNICODE),
            ]),
        ]);

        $result = $this->service->classifyAndRewrite(
            originalTitle: '元タイトル',
            categories: [
                ['id' => 10, 'name' => 'テクノロジー'],
                ['id' => 99, 'name' => '未分類'],
            ],
        );

        $this->assertSame(10, $result['category_id']);
        $this->assertSame('完成タイトル', $result['rewritten_title']);

        Http::assertSent(function ($request): bool {
            $data = $request->data();

            return $request->url() === 'https://ollama.unicorn.tokyo:11434/api/generate'
                && $data['stream'] === false
                && $data['format'] === 'json'
                && $data['model'] === 'gemma4:e2b';
        });
    }

    public function test_classify_and_rewrite_falls_back_when_json_is_invalid(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://ollama.unicorn.tokyo:11434/api/generate' => Http::response([
                'response' => '{invalid-json}',
            ]),
        ]);

        $result = $this->service->classifyAndRewrite(
            originalTitle: '元タイトル',
            categories: [
                ['id' => 10, 'name' => 'テクノロジー'],
                ['id' => 99, 'name' => '未分類'],
            ],
        );

        $this->assertSame(99, $result['category_id']);
        $this->assertSame('元タイトル', $result['rewritten_title']);
    }

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

    public function test_classify_and_rewrite_batch_uses_json_schema_and_fills_missing_items_by_fallback(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://ollama.unicorn.tokyo:11434/api/generate' => Http::response([
                'response' => json_encode([
                    'results' => [
                        [
                            'article_id' => 1,
                            'rewritten_title' => '完成タイトル',
                            'category_id' => 10,
                        ],
                    ],
                ], JSON_UNESCAPED_UNICODE),
            ]),
        ]);

        $result = $this->service->classifyAndRewriteBatch(
            articles: [
                ['id' => 1, 'title' => '元タイトル1'],
                ['id' => 2, 'title' => '元タイトル2'],
            ],
            categories: [
                ['id' => 10, 'name' => 'テクノロジー'],
                ['id' => 99, 'name' => '未分類'],
            ],
        );

        $this->assertSame(10, $result[1]['category_id']);
        $this->assertSame('完成タイトル', $result[1]['rewritten_title']);
        $this->assertSame(99, $result[2]['category_id']);
        $this->assertSame('元タイトル2', $result[2]['rewritten_title']);

        Http::assertSent(function ($request): bool {
            $data = $request->data();

            return $request->url() === 'https://ollama.unicorn.tokyo:11434/api/generate'
                && $data['stream'] === false
                && is_array($data['format'])
                && ($data['format']['type'] ?? null) === 'object'
                && ($data['format']['required'] ?? []) === ['results'];
        });
    }

    public function test_classify_and_rewrite_batch_returns_full_fallback_on_connection_error(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://ollama.unicorn.tokyo:11434/api/generate' => Http::failedConnection(),
        ]);

        $result = $this->service->classifyAndRewriteBatch(
            articles: [
                ['id' => 1, 'title' => '元タイトル1'],
                ['id' => 2, 'title' => '元タイトル2'],
            ],
            categories: [
                ['id' => 10, 'name' => 'テクノロジー'],
                ['id' => 99, 'name' => '未分類'],
            ],
        );

        $this->assertSame(99, $result[1]['category_id']);
        $this->assertSame('元タイトル1', $result[1]['rewritten_title']);
        $this->assertSame(99, $result[2]['category_id']);
        $this->assertSame('元タイトル2', $result[2]['rewritten_title']);
    }
}
