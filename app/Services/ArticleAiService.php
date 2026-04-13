<?php

declare(strict_types=1);

namespace App\Services;

use App\Ai\Agents\BatchCategorizeAgent;
use App\Ai\Agents\CategorizeArticleAgent;
use App\Models\App;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use RuntimeException;

class ArticleAiService
{
    /**
     * 記事の元タイトル、カテゴリ一覧をもとに、
     * AIによる分類とタイトルリライトを1回のリクエストで実行します。
     *
     * @param  string  $originalTitle  記事の元タイトル
     * @param  array  $categories  カテゴリ一覧 [['id' => int, 'name' => string], ...]
     * @return array{category_id: int, rewritten_title: string}
     *
     * @throws InvalidArgumentException
     * @throws RuntimeException
     */
    public function classifyAndRewrite(
        string $originalTitle,
        array $categories,
        string $driver = 'gemini',
        ?App $app = null
    ): array {
        if (empty($categories)) {
            throw new InvalidArgumentException('カテゴリ一覧が空です。少なくともで1件のカテゴリが必要です。');
        }

        if ($driver === 'ollama') {
            return $this->processWithOllama($originalTitle, $categories, $app);
        }

        $prompt = $this->buildPrompt($originalTitle, $categories, $app);

        // App別設定があればそれを使用、なければグローバル設定にフォールバック
        $geminiModel = (! empty($app?->gemini_model))
            ? $app->gemini_model
            : Cache::get('gemini_model', 'gemini-1.5-flash-lite');

        Log::info("[デバッグ]AI送信プロンプト:\n".$prompt);

        $response = CategorizeArticleAgent::make()->prompt($prompt, model: $geminiModel);

        if (is_string($response)) {
            $parsedResponse = $this->extractJsonResponse($response);
            if ($parsedResponse) {
                return $parsedResponse;
            }

            Log::warning('AI Response Parse Failed (Gemini). Raw Text: '.$response);
            throw new RuntimeException('Gemini generation failed or returned invalid JSON structure.');
        }

        return [
            'category_id' => (int) $response['category_id'],
            'rewritten_title' => (string) $response['rewritten_title'],
        ];
    }

    /**
     * Ollama APIを使用してカテゴリ分類とタイトルリライトを行います。
     * JSONフォーマットのみを出力するよう厳格に指定します。
     *
     * @return array{category_id: int, rewritten_title: string}
     */
    private function processWithOllama(string $title, array $categories, ?App $app = null): array
    {
        $prompt = $this->buildPrompt($title, $categories, $app);
        $ollamaUrl = config('services.ollama.url', 'http://host.docker.internal:11434/api/generate');

        $model = (! empty($app?->ollama_model))
            ? $app->ollama_model
            : Cache::get('ollama_model', 'qwen3.5:9b');

        $numPredict = $app?->ollama_num_predict ?? Cache::get('ollama_num_predict', 3000);
        $numCtx = $app?->ollama_num_ctx ?? Cache::get('ollama_num_ctx', 8192);

        Log::info("[デバッグ]AI送信プロンプト:\n".$prompt);

        try {
            $response = Http::timeout(120)->post($ollamaUrl, [
                'model' => $model,
                'prompt' => $prompt,
                'stream' => false,
                'format' => 'json',
                'options' => [
                    'num_predict' => (int) $numPredict,
                    'num_ctx' => (int) $numCtx,
                    'repeat_penalty' => 1.0,
                    'temperature' => 0.2,
                ],
            ]);

            $jsonResponse = $response->json();

            if ($response->failed() || ! is_array($jsonResponse)) {
                Log::error('Ollama API Error. Body: '.$response->body());
                throw new RuntimeException('Ollama API HTTP error or invalid response mapping.');
            }

            $rawText = ($jsonResponse['response'] ?? '')."\n".($jsonResponse['thinking'] ?? '');

            $parsedResponse = $this->extractJsonResponse($rawText);
            if ($parsedResponse) {
                return $parsedResponse;
            }

            Log::warning('AI Response Parse Failed (Ollama). Raw Text: '.$response->body());
            throw new RuntimeException('Ollama generation returned unparseable text.');
        } catch (\Exception $e) {
            Log::error('AI推論エラー: '.$e->getMessage());
            throw $e;
        }
    }

    /**
     * AIのテキストからJSONフォーマットを抽出し、カテゴリIDと書き換えたタイトルを取得します。
     *
     * @return array{category_id: int, rewritten_title: string}|null
     */
    private function extractJsonResponse(string $text): ?array
    {
        preg_match_all('/\{(?:[^{}]|(?R))*\}/x', $text, $matches);

        if (empty($matches[0])) {
            return null;
        }

        foreach ($matches[0] as $match) {
            $data = json_decode($match, true);
            if (is_array($data)) {
                $rewrittenTitle = $data['rewritten_title'] ?? $data['rewrite'] ?? $data['title'] ?? null;
                $categoryId = $data['category_id'] ?? $data['category'] ?? null;

                if ($rewrittenTitle !== null && $categoryId !== null) {
                    return [
                        'category_id' => (int) $categoryId,
                        'rewritten_title' => (string) $rewrittenTitle,
                    ];
                }
            }
        }

        return null;
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

        // アプリ設定またはシステム設定（Cache）からのみ取得
        $template = (! empty($app?->ai_prompt_template))
            ? $app->ai_prompt_template
            : Cache::get('ai_prompt_template');

        // テンプレートが存在しない場合は例外を投げる
        if (empty($template)) {
            throw new RuntimeException('AIプロンプトのテンプレートが設定されていません。システム設定またはアプリ設定を確認してください。');
        }

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
        string $driver = 'gemini',
        ?App $app = null
    ): array {
        if (empty($categories)) {
            throw new InvalidArgumentException('カテゴリ一覧が空です。少なくとも1件のカテゴリが必要です。');
        }

        if (empty($articles)) {
            return [];
        }

        $prompt = $this->buildBatchPrompt($articles, $categories, $app);

        return $driver === 'ollama'
            ? $this->batchWithOllama($prompt, $app)
            : $this->batchWithGemini($prompt, $app);
    }

    /**
     * GeminiでバッチAI処理を実行します。
     *
     * @return array<int, array{category_id: int, rewritten_title: string}>
     */
    private function batchWithGemini(string $prompt, ?App $app = null): array
    {
        $geminiModel = (! empty($app?->gemini_model))
            ? $app->gemini_model
            : Cache::get('gemini_model', 'gemini-1.5-flash-lite');

        Log::info("[バッチ]AI送信プロンプト:\n".$prompt);

        $response = BatchCategorizeAgent::make()->prompt($prompt, model: $geminiModel);

        return $this->extractBatchJsonResponse($response);
    }

    /**
     * OllamaでバッチAI処理を実行します。
     *
     * @return array<int, array{category_id: int, rewritten_title: string}>
     */
    private function batchWithOllama(string $prompt, ?App $app = null): array
    {
        $ollamaUrl = config('services.ollama.url', 'http://host.docker.internal:11434/api/generate');
        $model = (! empty($app?->ollama_model))
            ? $app->ollama_model
            : Cache::get('ollama_model', 'qwen3.5:9b');

        $numPredict = $app?->ollama_num_predict ?? Cache::get('ollama_num_predict', 3000);
        $numCtx = $app?->ollama_num_ctx ?? Cache::get('ollama_num_ctx', 8192);

        Log::info("[バッチ]AI送信プロンプト:\n".$prompt);

        try {
            $response = Http::timeout(180)->post($ollamaUrl, [
                'model' => $model,
                'prompt' => $prompt,
                'stream' => false,
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
                'options' => [
                    'num_predict' => (int) $numPredict,
                    'num_ctx' => (int) $numCtx,
                    'repeat_penalty' => 1.0,
                    'temperature' => 0.2,
                ],
            ]);

            $jsonResponse = $response->json();

            if ($response->failed() || ! is_array($jsonResponse) || empty($jsonResponse['response'])) {
                Log::error('[バッチ] Ollama API Error. Body: '.$response->body());

                return [];
            }

            $rawText = $jsonResponse['response'];

            return $this->extractBatchJsonResponse($rawText);
        } catch (\Exception $e) {
            Log::error('[バッチ] AI推論エラー: '.$e->getMessage());

            return [];
        }
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

        $basePrompt = Cache::get('ai_base_prompt', '提示された記事を分析し、最適なカテゴリを選び、クリックしたくなる魅力的なタイトルにリライトしてください。');
        $appPrompt = $app?->ai_prompt_template ?? '';

        $prompt = trim($basePrompt . "\n\n" . $appPrompt);
        
        // 以前の {categories} プレースホルダなどがあれば変換（後方互換性のため残しつつ、末尾にも追記）
        $prompt = str_replace(
            ['{categories}', '{articles_json}', '{title}'],
            [$categoryList, $articlesJson, $articlesJson],
            $prompt
        );

        if (!str_contains($prompt, $categoryList)) {
            $prompt .= "\n\n【カテゴリ一覧】\n" . $categoryList;
        }
        
        if (!str_contains($prompt, $articlesJson)) {
            $prompt .= "\n\n【処理対象記事データ】\n" . $articlesJson;
        }

        $count = count($articles);
        $prompt .= "\n\n今回は全部で {$count} 件です。出力するJSON配列の要素数は、絶対に {$count} 件と完全に一致させなければなりません。1件も省略せず、最後まで出力してください。";

        return $prompt;
    }

    /**
     * AIの返答からJSONオブジェクト群を抽出・パースして記事IDをキーとした結果配列を返します。
     *
     * @param array|string $data
     * @return array<int, array{category_id: int, rewritten_title: string}>
     */
    private function extractBatchJsonResponse(array|string $data): array
    {
        $decoded = is_string($data) ? json_decode($data, true) : $data;

        if (is_string($data) && (json_last_error() !== JSON_ERROR_NONE || ! is_array($decoded))) {
            Log::warning('[バッチ] JSONのパースに失敗しました。Raw: '.mb_substr($data, 0, 500));

            return [];
        }

        if (! is_array($decoded)) {
            return [];
        }

        $items = $decoded['results'] ?? $decoded;

        if (! is_array($items)) {
            Log::warning('[バッチ] 結果配列(results)が見つかりません。', ['decoded' => $decoded]);
            return [];
        }

        $results = [];

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $articleId = $item['article_id'] ?? null;
            $categoryId = $item['category_id'] ?? null;
            $rewrittenTitle = $item['rewritten_title'] ?? null;

            if ($articleId !== null && $categoryId !== null && $rewrittenTitle !== null) {
                $results[(int) $articleId] = [
                    'category_id' => (int) $categoryId,
                    'rewritten_title' => (string) $rewrittenTitle,
                ];
            }
        }

        if (empty($results)) {
            Log::warning('[バッチ] 抽出可能な記事データが見つかりませんでした。', ['decoded' => $decoded]);
        }

        return $results;
    }
}
