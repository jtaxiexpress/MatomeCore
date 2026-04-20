<?php

declare(strict_types=1);

namespace App\Services;

use App\Filament\Pages\SystemSettings;
use App\Models\App;
use App\Models\SystemSetting;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

class ArticleAiService
{
    private const DEFAULT_SINGLE_PROMPT_TEMPLATE = <<<'PROMPT'
以下のカテゴリ一覧から最も適切な category_id を1つ選び、元タイトルを自然な日本語でリライトしてください。

カテゴリ一覧:
{categories}

元タイトル:
{title}

必ず JSON で以下の形式のみを返してください:
{"category_id": 1, "rewritten_title": "..."}
PROMPT;

    /**
     * 記事の元タイトル、カテゴリ一覧をもとに、
     * AIによる分類とタイトルリライトを1回のリクエストで実行します。
     *
     * @param  string  $originalTitle  記事の元タイトル
     * @param  array  $categories  カテゴリ一覧 [['id' => int, 'name' => string], ...]
     * @return array{category_id: int, rewritten_title: string}
     *
     * @throws InvalidArgumentException
     */
    public function classifyAndRewrite(
        string $originalTitle,
        array $categories,
        ?App $app = null
    ): array {
        if (empty($categories)) {
            throw new InvalidArgumentException('カテゴリ一覧が空です。少なくともで1件のカテゴリが必要です。');
        }

        $prompt = $this->buildPrompt($originalTitle, $categories, $app);
        $model = $this->ollamaModel();
        $payload = $this->buildSinglePayload($prompt, $model);

        Log::info('[AI] 単体推論を開始', [
            'provider' => 'ollama',
            'model' => $model,
            'title_length' => mb_strlen($originalTitle),
            'categories' => count($categories),
        ]);

        $response = $this->requestOllama($payload, timeoutSeconds: 120, operation: '単体推論');

        if (! is_array($response)) {
            return $this->singleFallbackResult($originalTitle, $categories, 'request_failed');
        }

        $decoded = $this->decodeStructuredResponse($response, '単体推論');

        if (! is_array($decoded)) {
            return $this->singleFallbackResult($originalTitle, $categories, 'json_decode_failed');
        }

        $parsed = $this->parseSingleResult($decoded, $categories);

        if (is_array($parsed)) {
            return $parsed;
        }

        return $this->singleFallbackResult($originalTitle, $categories, 'unexpected_json_shape');
    }

    /**
     * AIエージェントへ送信するプロンプトを構築します。
     */
    private function buildPrompt(
        string $originalTitle,
        array $categories,
        ?App $app = null
    ): string {
        $categoryList = collect($categories)
            ->map(function (array $cat): string {
                $label = isset($cat['parent_name'])
                    ? "{$cat['parent_name']} > {$cat['name']} (ID: {$cat['id']})"
                    : "{$cat['name']} (ID: {$cat['id']})";

                return "- {$label}";
            })
            ->implode("\n");

        // アプリ設定 > Cache > DB(system_settings) > デフォルトの順で取得する
        $template = (! empty($app?->ai_prompt_template))
            ? $app->ai_prompt_template
            : $this->singlePromptTemplate();

        return str_replace(['{categories}', '{title}'], [$categoryList, $originalTitle], $template);
    }

    // =========================================================================
    // バッチ処理 API
    // =========================================================================

    /**
     * 複数記事のカテゴリ分類とタイトルリライトを1回のAIリクエストで実行します。
     *
     * @param  array<int, array{id: int, title: string}>  $articles  処理対象の記事配列
     * @param  array<int, array{id: int, name: string}>  $categories  カテゴリ一覧
     * @return array<int, array{category_id: int, rewritten_title: string}> 記事IDをキーとした結果配列
     *
     * @throws InvalidArgumentException
     */
    public function classifyAndRewriteBatch(
        array $articles,
        array $categories,
        ?App $app = null
    ): array {
        if (empty($categories)) {
            throw new InvalidArgumentException('カテゴリ一覧が空です。少なくとも1件のカテゴリが必要です。');
        }

        if (empty($articles)) {
            return [];
        }

        $prompt = $this->buildBatchPrompt($articles, $categories, $app);
        $model = $this->ollamaModel();
        $payload = $this->buildBatchPayload($prompt, $model);

        Log::debug('[AI] 送信プロンプト:'."\n".$prompt);

        Log::info('[AI] バッチ推論を開始', [
            'provider' => 'ollama',
            'model' => $model,
            'payload_length' => mb_strlen($prompt),
            'articles' => count($articles),
        ]);

        $response = $this->requestOllama($payload, timeoutSeconds: 180, operation: 'バッチ推論');

        if (! is_array($response)) {
            return $this->buildBatchFallbackResults($articles, $categories);
        }

        $decoded = $this->decodeStructuredResponse($response, 'バッチ推論');

        if (! is_array($decoded)) {
            return $this->buildBatchFallbackResults($articles, $categories);
        }

        return $this->parseBatchResults($decoded, $articles, $categories);
    }

    /**
     * バッチ処理用のプロンプトを構築します。
     *
     * @param  array<int, array{id: int, title: string}>  $articles
     * @param  array<int, array{id: int, name: string}>  $categories
     */
    private function buildBatchPrompt(array $articles, array $categories, ?App $app = null): string
    {
        $categoryList = collect($categories)
            ->map(function (array $cat): string {
                $label = isset($cat['parent_name'])
                    ? "{$cat['parent_name']} > {$cat['name']} (ID: {$cat['id']})"
                    : "{$cat['name']} (ID: {$cat['id']})";

                return "- {$label}";
            })
            ->implode("\n");

        $articlesJson = json_encode(
            array_map(fn (array $a): array => ['article_id' => $a['id'], 'title' => $a['title']], $articles),
            JSON_UNESCAPED_UNICODE
        );

        $basePrompt = $this->basePromptTemplate();
        $appPrompt = $app?->ai_prompt_template ?? '';

        $count = count($articles);

        $prompt = str_replace(
            ['{app_prompt}', '{categories}', '{articles_json}', '{count}'],
            [$appPrompt, $categoryList, $articlesJson, (string) $count],
            $basePrompt
        );

        return trim($prompt);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>|null
     */
    private function requestOllama(array $payload, int $timeoutSeconds, string $operation): ?array
    {
        try {
            $response = Http::connectTimeout(10)
                ->timeout($timeoutSeconds)
                ->retry(2, 400)
                ->post($this->ollamaGenerateUrl(), $payload)
                ->throw(function (Response $response, RequestException $e) use ($operation): void {
                    Log::error('[AI] Ollama APIエラー', [
                        'operation' => $operation,
                        'status' => $response->status(),
                        'body_preview' => mb_substr($response->body(), 0, 200),
                        'message' => $e->getMessage(),
                    ]);
                });
        } catch (ConnectionException $e) {
            Log::error('[AI] Ollama接続エラー', [
                'operation' => $operation,
                'message' => $e->getMessage(),
            ]);

            return null;
        } catch (RequestException) {
            return null;
        } catch (\Throwable $e) {
            Log::error('[AI] Ollama通信例外', [
                'operation' => $operation,
                'message' => $e->getMessage(),
            ]);

            return null;
        }

        $json = $response->json();

        if (! is_array($json)) {
            Log::error('[AI] OllamaレスポンスがJSONオブジェクトではありません', [
                'operation' => $operation,
                'body_preview' => mb_substr($response->body(), 0, 200),
            ]);

            return null;
        }

        return $json;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function buildSinglePayload(string $prompt, string $model): array
    {
        return [
            'model' => $model,
            'prompt' => $prompt,
            'stream' => (bool) config('ai.providers.ollama.stream', false),
            'format' => 'json',
            'options' => $this->ollamaOptions(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildBatchPayload(string $prompt, string $model): array
    {
        return [
            'model' => $model,
            'prompt' => $prompt,
            'stream' => (bool) config('ai.providers.ollama.stream', false),
            'format' => [
                'type' => 'object',
                'properties' => [
                    'results' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'article_id' => ['type' => 'integer'],
                                'rewritten_title' => ['type' => 'string'],
                                'category_id' => ['type' => 'integer'],
                            ],
                            'required' => ['article_id', 'rewritten_title', 'category_id'],
                        ],
                    ],
                ],
                'required' => ['results'],
            ],
            'options' => $this->ollamaOptions(),
        ];
    }

    private function ollamaGenerateUrl(): string
    {
        $base = rtrim((string) config('ai.providers.ollama.url', 'https://ollama.unicorn.tokyo'), '/');

        if (str_ends_with($base, '/api/generate')) {
            return $base;
        }

        if (str_ends_with($base, '/api')) {
            return $base.'/generate';
        }

        return $base.'/api/generate';
    }

    private function ollamaModel(): string
    {
        return $this->readStringSetting(
            cacheKey: 'ollama_model',
            default: (string) config('ai.providers.ollama.model', 'gemma4:e2b'),
        );
    }

    /**
     * @return array{num_predict: int, num_ctx: int, temperature: float, repeat_penalty: float}
     */
    private function ollamaOptions(): array
    {
        /** @var array<string, mixed> $configOptions */
        $configOptions = config('ai.providers.ollama.options', []);

        return [
            'num_predict' => $this->readIntSetting(
                cacheKey: 'ollama_num_predict',
                default: (int) ($configOptions['num_predict'] ?? 3000),
            ),
            'num_ctx' => $this->readIntSetting(
                cacheKey: 'ollama_num_ctx',
                default: (int) ($configOptions['num_ctx'] ?? 8192),
            ),
            'temperature' => (float) ($configOptions['temperature'] ?? 0.2),
            'repeat_penalty' => (float) ($configOptions['repeat_penalty'] ?? 1.0),
        ];
    }

    private function singlePromptTemplate(): string
    {
        return $this->readStringSetting(
            cacheKey: 'ai_prompt_template',
            default: self::DEFAULT_SINGLE_PROMPT_TEMPLATE,
        );
    }

    private function basePromptTemplate(): string
    {
        return $this->readStringSetting(
            cacheKey: 'ai_base_prompt',
            default: SystemSettings::getDefaultPromptTemplate(),
        );
    }

    private function readStringSetting(string $cacheKey, string $default): string
    {
        $value = Cache::rememberForever($cacheKey, function () use ($cacheKey, $default): string {
            return SystemSetting::getValue($cacheKey) ?? $default;
        });

        return is_string($value) && $value !== '' ? $value : $default;
    }

    private function readIntSetting(string $cacheKey, int $default): int
    {
        $value = Cache::rememberForever($cacheKey, function () use ($cacheKey, $default): string {
            return SystemSetting::getValue($cacheKey) ?? (string) $default;
        });

        return is_numeric($value) ? (int) $value : $default;
    }

    /**
     * @param  array<string, mixed>  $response
     * @return array<string, mixed>|null
     */
    private function decodeStructuredResponse(array $response, string $operation): ?array
    {
        $structuredText = $response['response'] ?? null;

        if (! is_string($structuredText) || trim($structuredText) === '') {
            Log::warning('[AI] Ollamaレスポンスにresponse文字列がありません', [
                'operation' => $operation,
                'response_keys' => array_keys($response),
            ]);

            return null;
        }

        $decoded = json_decode($structuredText, true);

        if (! is_array($decoded) || json_last_error() !== JSON_ERROR_NONE) {
            Log::warning('[AI] Structured OutputのJSONデコードに失敗', [
                'operation' => $operation,
                'preview' => mb_substr($structuredText, 0, 200),
                'json_error' => json_last_error_msg(),
            ]);

            return null;
        }

        return $decoded;
    }

    /**
     * @param  array<string, mixed>  $decoded
     * @param  array<int, array{id: int, name: string}>  $categories
     * @return array{category_id: int, rewritten_title: string}|null
     */
    private function parseSingleResult(array $decoded, array $categories): ?array
    {
        $categoryId = $decoded['category_id'] ?? null;
        $rewrittenTitle = $decoded['rewritten_title'] ?? null;

        if (! is_numeric($categoryId) || ! is_string($rewrittenTitle) || trim($rewrittenTitle) === '') {
            Log::warning('[AI] 単体Structured Outputの構造が不正です', [
                'received_keys' => array_keys($decoded),
            ]);

            return null;
        }

        $normalizedCategoryId = (int) $categoryId;
        $allowedCategoryIds = array_flip(array_map(
            static fn (array $category): int => (int) $category['id'],
            $categories
        ));

        if (! isset($allowedCategoryIds[$normalizedCategoryId])) {
            Log::warning('[AI] 単体Structured Outputのcategory_idがカテゴリ一覧外です', [
                'category_id' => $normalizedCategoryId,
            ]);

            return null;
        }

        return [
            'category_id' => $normalizedCategoryId,
            'rewritten_title' => trim($rewrittenTitle),
        ];
    }

    /**
     * @param  array<string, mixed>  $decoded
     * @param  array<int, array{id: int, title: string}>  $articles
     * @param  array<int, array{id: int, name: string}>  $categories
     * @return array<int, array{category_id: int, rewritten_title: string}>
     */
    private function parseBatchResults(array $decoded, array $articles, array $categories): array
    {
        $fallbackResults = $this->buildBatchFallbackResults($articles, $categories);
        $items = $decoded['results'] ?? null;

        if (! is_array($items)) {
            Log::warning('[AI] バッチStructured Outputにresults配列がありません');

            return $fallbackResults;
        }

        $allowedCategoryIds = array_flip(array_map(
            static fn (array $category): int => (int) $category['id'],
            $categories
        ));
        $results = $fallbackResults;
        $resolvedByAi = [];

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $articleId = $item['article_id'] ?? null;
            $categoryId = $item['category_id'] ?? null;
            $rewrittenTitle = $item['rewritten_title'] ?? null;

            if (! is_numeric($articleId) || ! is_numeric($categoryId) || ! is_string($rewrittenTitle) || trim($rewrittenTitle) === '') {
                continue;
            }

            $normalizedArticleId = (int) $articleId;
            $normalizedCategoryId = (int) $categoryId;

            if (! isset($fallbackResults[$normalizedArticleId])) {
                continue;
            }

            if (! isset($allowedCategoryIds[$normalizedCategoryId])) {
                continue;
            }

            $results[$normalizedArticleId] = [
                'category_id' => $normalizedCategoryId,
                'rewritten_title' => trim($rewrittenTitle),
            ];
            $resolvedByAi[$normalizedArticleId] = true;
        }

        $fallbackCount = count($fallbackResults) - count($resolvedByAi);

        if ($fallbackCount > 0) {
            Log::warning('[AI] バッチ結果の一部をフォールバックで補完しました', [
                'total' => count($fallbackResults),
                'fallback_count' => $fallbackCount,
            ]);
        }

        return $results;
    }

    /**
     * @param  array<int, array{id: int, name: string}>  $categories
     * @return array{category_id: int, rewritten_title: string}
     */
    private function singleFallbackResult(string $originalTitle, array $categories, string $reason): array
    {
        $categoryId = $this->resolveFallbackCategoryId($categories);

        Log::warning('[AI] 単体結果をフォールバックしました', [
            'reason' => $reason,
            'fallback_category_id' => $categoryId,
        ]);

        return [
            'category_id' => $categoryId,
            'rewritten_title' => $originalTitle,
        ];
    }

    /**
     * @param  array<int, array{id: int, title: string}>  $articles
     * @param  array<int, array{id: int, name: string}>  $categories
     * @return array<int, array{category_id: int, rewritten_title: string}>
     */
    private function buildBatchFallbackResults(array $articles, array $categories): array
    {
        $fallbackCategoryId = $this->resolveFallbackCategoryId($categories);
        $results = [];

        foreach ($articles as $article) {
            $articleId = $article['id'] ?? null;
            $title = $article['title'] ?? null;

            if (! is_numeric($articleId) || ! is_string($title)) {
                continue;
            }

            $results[(int) $articleId] = [
                'category_id' => $fallbackCategoryId,
                'rewritten_title' => $title,
            ];
        }

        return $results;
    }

    /**
     * @param  array<int, array{id: int, name: string}>  $categories
     */
    private function resolveFallbackCategoryId(array $categories): int
    {
        $markers = ['未分類', 'uncategorized', 'その他', 'misc'];
        $firstCategoryId = null;

        foreach ($categories as $category) {
            if (! is_array($category)) {
                continue;
            }

            $categoryId = $category['id'] ?? null;

            if (! is_numeric($categoryId)) {
                continue;
            }

            $normalizedId = (int) $categoryId;
            $firstCategoryId ??= $normalizedId;
            $categoryName = mb_strtolower((string) ($category['name'] ?? ''));

            foreach ($markers as $marker) {
                if ($categoryName !== '' && mb_stripos($categoryName, mb_strtolower($marker)) !== false) {
                    return $normalizedId;
                }
            }
        }

        if ($firstCategoryId === null) {
            throw new InvalidArgumentException('カテゴリ一覧に有効なカテゴリIDがありません。');
        }

        return $firstCategoryId;
    }
}
