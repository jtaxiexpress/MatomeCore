<?php

declare(strict_types=1);

namespace App\Services;

use App\Clients\OllamaClient;
use App\Filament\Pages\SystemSettings;
use App\Models\App;
use App\Models\SystemSetting;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use InvalidArgumentException;
use Throwable;

class ArticleAiService
{
    public function __construct(
        private readonly OllamaClient $ollamaClient,
    ) {}

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
     * @param  array<int, array{id: int, name: string, parent_name?: string}>  $categories  カテゴリ一覧
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

        $decoded = $this->requestStructuredData($payload, timeoutSeconds: 120, operation: '単体推論');

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
    /**
     * @param  array<int, array{id: int, name: string, parent_name?: string}>  $categories
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
        $template = ($app instanceof App && filled($app->ai_prompt_template))
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
     * @param  array<int, array{id: int, name: string, parent_name?: string}>  $categories  カテゴリ一覧
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

        $decoded = $this->requestStructuredData($payload, timeoutSeconds: 180, operation: 'バッチ推論');

        if (! is_array($decoded)) {
            return $this->buildBatchFallbackResults($articles, $categories);
        }

        return $this->parseBatchResults($decoded, $articles, $categories);
    }

    /**
     * バッチ処理用のプロンプトを構築します。
     *
     * @param  array<int, array{id: int, title: string}>  $articles
     * @param  array<int, array{id: int, name: string, parent_name?: string}>  $categories
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
        $appPrompt = ($app instanceof App && is_string($app->ai_prompt_template))
            ? $app->ai_prompt_template
            : '';

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
     *
     * @throws ConnectionException
     * @throws RequestException
     */
    private function requestStructuredData(array $payload, int $timeoutSeconds, string $operation): ?array
    {
        try {
            return $this->ollamaClient->generateStructuredResponse($payload, $timeoutSeconds, $operation);
        } catch (ConnectionException|RequestException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            Log::warning('[AI] Structured Output取得に失敗したためフォールバックします', [
                'operation' => $operation,
                'message' => $exception->getMessage(),
            ]);

            return null;
        }
    }

    /**
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

        return $value !== '' ? $value : $default;
    }

    private function readIntSetting(string $cacheKey, int $default): int
    {
        $value = Cache::rememberForever($cacheKey, function () use ($cacheKey, $default): string {
            return SystemSetting::getValue($cacheKey) ?? (string) $default;
        });

        return is_numeric($value) ? (int) $value : $default;
    }

    /**
     * @param  array<string, mixed>  $decoded
     * @param  array<int, array{id: int, name: string, parent_name?: string}>  $categories
     * @return array{category_id: int, rewritten_title: string}|null
     */
    private function parseSingleResult(array $decoded, array $categories): ?array
    {
        $allowedCategoryIds = array_map(
            static fn (array $category): int => (int) $category['id'],
            $categories
        );

        $validator = Validator::make($decoded, [
            'category_id' => ['required', 'integer', Rule::in($allowedCategoryIds)],
            'rewritten_title' => ['required', 'string'],
        ]);

        if ($validator->fails()) {
            Log::warning('[AI] 単体Structured Outputの構造が不正です', [
                'errors' => $validator->errors()->toArray(),
            ]);

            return null;
        }

        /** @var array{category_id: int, rewritten_title: string} $validated */
        $validated = $validator->validated();
        $rewrittenTitle = trim($validated['rewritten_title']);

        if ($rewrittenTitle === '') {
            Log::warning('[AI] 単体Structured Outputのrewritten_titleが空白のみです');

            return null;
        }

        return [
            'category_id' => (int) $validated['category_id'],
            'rewritten_title' => $rewrittenTitle,
        ];
    }

    /**
     * @param  array<string, mixed>  $decoded
     * @param  array<int, array{id: int, title: string}>  $articles
     * @param  array<int, array{id: int, name: string, parent_name?: string}>  $categories
     * @return array<int, array{category_id: int, rewritten_title: string}>
     */
    private function parseBatchResults(array $decoded, array $articles, array $categories): array
    {
        $fallbackResults = $this->buildBatchFallbackResults($articles, $categories);
        $allowedCategoryIds = array_map(
            static fn (array $category): int => (int) $category['id'],
            $categories
        );

        $validator = Validator::make($decoded, [
            'results' => ['required', 'array'],
            'results.*.article_id' => ['required', 'integer'],
            'results.*.category_id' => ['required', 'integer', Rule::in($allowedCategoryIds)],
            'results.*.rewritten_title' => ['required', 'string'],
        ]);

        if ($validator->fails()) {
            Log::warning('[AI] バッチStructured Outputの検証に失敗しました', [
                'errors' => $validator->errors()->toArray(),
            ]);

            return $fallbackResults;
        }
        /** @var array<int, array{article_id: int, category_id: int, rewritten_title: string}> $items */
        $items = $validator->validated()['results'];
        $results = $fallbackResults;
        $resolvedByAi = [];

        foreach ($items as $item) {
            $normalizedArticleId = (int) $item['article_id'];
            $normalizedCategoryId = (int) $item['category_id'];
            $rewrittenTitle = trim($item['rewritten_title']);

            if (! isset($fallbackResults[$normalizedArticleId])) {
                continue;
            }

            if ($rewrittenTitle === '') {
                continue;
            }

            $results[$normalizedArticleId] = [
                'category_id' => $normalizedCategoryId,
                'rewritten_title' => $rewrittenTitle,
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
     * @param  array<int, array{id: int, name: string, parent_name?: string}>  $categories
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
     * @param  array<int, array{id: int, name: string, parent_name?: string}>  $categories
     * @return array<int, array{category_id: int, rewritten_title: string}>
     */
    private function buildBatchFallbackResults(array $articles, array $categories): array
    {
        $fallbackCategoryId = $this->resolveFallbackCategoryId($categories);
        $results = [];

        foreach ($articles as $article) {
            $results[$article['id']] = [
                'category_id' => $fallbackCategoryId,
                'rewritten_title' => $article['title'],
            ];
        }

        return $results;
    }

    /**
     * @param  array<int, array{id: int, name: string, parent_name?: string}>  $categories
     */
    private function resolveFallbackCategoryId(array $categories): int
    {
        $markers = ['未分類', 'uncategorized', 'その他', 'misc'];
        $firstCategoryId = null;

        foreach ($categories as $category) {
            $normalizedId = $category['id'];
            $firstCategoryId ??= $normalizedId;
            $categoryName = mb_strtolower($category['name']);

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
