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

        Log::info("[デバッグ]AI送信プロンプト:\n".$prompt);

        try {
            $response = Http::timeout(120)->post($ollamaUrl, [
                'model' => $model,
                'prompt' => $prompt,
                'stream' => false,
                'format' => 'json',
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
            throw new \RuntimeException('AIプロンプトのテンプレートが設定されていません。システム設定またはアプリ設定を確認してください。');
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
        $rawText = is_string($response) ? $response : (string) $response;

        return $this->extractBatchJsonResponse($rawText);
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

        Log::info("[バッチ]AI送信プロンプト:\n".$prompt);

        try {
            $response = Http::timeout(180)->post($ollamaUrl, [
                'model' => $model,
                'prompt' => $prompt,
                'stream' => false,
                'format' => 'json',
            ]);

            $jsonResponse = $response->json();

            if ($response->failed() || ! is_array($jsonResponse)) {
                Log::error('[バッチ] Ollama API Error. Body: '.$response->body());

                return [];
            }

            $rawText = ($jsonResponse['response'] ?? '')."\n".($jsonResponse['thinking'] ?? '');

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

        // アプリ設定またはシステム設定（Cache）からのみ取得
        $template = (! empty($app?->ai_prompt_template))
            ? $app->ai_prompt_template
            : Cache::get('ai_prompt_template');

        // テンプレートが存在しない場合は例外を投げる
        if (empty($template)) {
            throw new \RuntimeException('[バッチ] AIプロンプトのテンプレートが設定されていません。システム設定またはアプリ設定を確認してください。');
        }

        // 管理画面のテンプレートで {title} が使われている場合も考慮し、{articles_json} に置換する
        return str_replace(
            ['{categories}', '{articles_json}', '{title}'],
            [$categoryList, $articlesJson, $articlesJson],
            $template
        );
    }

    /**
     * AIの返答からJSONオブジェクト群を抽出・パースして記事IDをキーとした結果配列を返します。
     * 部分的な成功（一部記事のみ返却）を許容し、例外を投げません。
     *
     * @return array<int, array{category_id: int, rewritten_title: string}>
     */
    private function extractBatchJsonResponse(string $text): array
    {
        // マークダウンのコードブロックなどを簡易的に除去
        $cleaned = preg_replace('/```(?:json)?\s*/i', '', $text);
        $cleaned = preg_replace('/```/', '', $cleaned ?? $text);
        $cleaned = trim($cleaned ?? $text);

        // JSONオブジェクト（{...}）を個別にすべて抽出。再帰的パターンでネストされた括弧にも対応。
        if (! preg_match_all('/\{(?:[^{}]|(?0))*\}/s', $cleaned, $matches) || empty($matches[0])) {
            Log::warning('[バッチ] JSONオブジェクトが見つかりませんでした。Raw: '.mb_substr($cleaned, 0, 500));

            return [];
        }

        $results = [];

        foreach ($matches[0] as $match) {
            $decoded = json_decode($match, true);

            if (! is_array($decoded)) {
                Log::warning('[バッチ] JSONオブジェクトのデコードに失敗しました。Raw: '.mb_substr($match, 0, 500));

                continue;
            }

            $articleId = $decoded['article_id'] ?? null;
            $categoryId = $decoded['category_id'] ?? null;
            $rewrittenTitle = $decoded['rewritten_title'] ?? null;

            if ($articleId === null || $categoryId === null || $rewrittenTitle === null) {
                Log::warning('[バッチ] 不正なアイテム形式をスキップしました。', ['item' => $decoded]);

                continue;
            }

            $results[(int) $articleId] = [
                'category_id' => (int) $categoryId,
                'rewritten_title' => (string) $rewrittenTitle,
            ];
        }

        return $results;
    }
}
