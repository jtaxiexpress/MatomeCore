<?php

declare(strict_types=1);

namespace App\Services;

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

        $template = (! empty($app?->ai_prompt_template))
            ? $app->ai_prompt_template
            : Cache::get('ai_prompt_template', $this->getDefaultPromptTemplate());

        return str_replace(['{categories}', '{title}'], [$categoryList, $originalTitle], $template);
    }

    /**
     * デフォルトのプロンプトテンプレート
     */
    private function getDefaultPromptTemplate(): string
    {
        return <<<'PROMPT'
あなたは優秀な編集者です。以下の情報を見て推論を行ってください。

## 利用可能なカテゴリ一覧
{categories}

## 元のタイトル
{title}

要件:
1. タイトルをキャッチーで分かりやすくリライトしてください。
2. 最も適切なカテゴリのIDを1つ選んでください。
3. 出力は必ず以下のJSON形式とし、マークダウンや解説は一切含めないでください:
{"rewritten_title": "新しいタイトル", "category_id": 1}
PROMPT;
    }
}
