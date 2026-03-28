<?php

namespace App\Services;

use App\Ai\Agents\CategorizeArticleAgent;
use App\Models\App;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use InvalidArgumentException;

class ArticleAiService
{
    /**
     * 記事の元タイトル、カテゴリ一覧をもとに、
     * AIによる分類とタイトルリライトを1回のリクエストで実行します。
     *
     * @param  string  $originalTitle  記事の元タイトル
     * @param  array   $categories     カテゴリ一覧 [['id' => int, 'name' => string], ...]
     * @return array{category_id: int, rewritten_title: string}
     *
     * @throws InvalidArgumentException
     * @throws \RuntimeException
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
        $geminiModel = (!empty($app?->gemini_model))
            ? $app->gemini_model
            : Cache::get('gemini_model', 'gemini-1.5-flash-lite');

        \Illuminate\Support\Facades\Log::info("[デバッグ]AI送信プロンプト:\n" . $prompt);

        $response = CategorizeArticleAgent::make()->prompt($prompt, model: $geminiModel);

        if (is_string($response)) {
            if (preg_match('/\{[\s\S]*\}/', $response, $matches)) {
                $data = json_decode($matches[0], true);
                if (is_array($data) && isset($data['rewritten_title'], $data['category_id'])) {
                    return [
                        'category_id'     => (int) $data['category_id'],
                        'rewritten_title' => (string) $data['rewritten_title'],
                    ];
                }
            }
            \Illuminate\Support\Facades\Log::warning('AI Response Parse Failed (Gemini). Raw Text: ' . $response);
            throw new \RuntimeException('Gemini generation failed or returned invalid JSON structure.');
        }

        return [
            'category_id'     => (int) $response['category_id'],
            'rewritten_title' => (string) $response['rewritten_title'],
        ];
    }

    /**
     * Ollama APIを使用してカテゴリ分類とタイトルリライトを行います。
     * JSONフォーマットのみを出力するよう厳格に指定します。
     */
    private function processWithOllama(string $title, array $categories, ?App $app = null): array
    {
        $prompt = $this->buildPrompt($title, $categories, $app);

        $ollamaUrl = config('services.ollama.url', 'http://host.docker.internal:11434/api/generate');

        // App別設定があればそれを使用、なければグローバル設定にフォールバック
        $model = (!empty($app?->ollama_model))
            ? $app->ollama_model
            : Cache::get('ollama_model', 'qwen3.5:9b');

        \Illuminate\Support\Facades\Log::info("[デバッグ]AI送信プロンプト:\n" . $prompt);

        try {
            $response = \Illuminate\Support\Facades\Http::timeout(120)->post($ollamaUrl, [
                'model' => $model,
                'prompt' => $prompt,
                'stream' => false,
                'format' => 'json'
            ]);

            $jsonResponse = $response->json();

            if ($response->failed() || !is_array($jsonResponse)) {
                \Illuminate\Support\Facades\Log::error('Ollama API Error. Body: ' . $response->body());
                throw new \RuntimeException('Ollama API HTTP error or invalid response mapping.');
            }

            // `response` または `thinking` フィールドを結合してテキスト全体を取得
            $rawText = ($jsonResponse['response'] ?? '') . "\n" . ($jsonResponse['thinking'] ?? '');
            
            if (is_string($rawText) && preg_match('/\{[\s\S]*\}/', $rawText, $matches)) {
                $data = json_decode($matches[0], true);

                if (is_array($data) && isset($data['rewritten_title'], $data['category_id'])) {
                    return [
                        'category_id'     => (int) $data['category_id'],
                        'rewritten_title' => (string) $data['rewritten_title'],
                    ];
                }
            }
            
            \Illuminate\Support\Facades\Log::warning('AI Response Parse Failed (Ollama). Raw Text: ' . $response->body());
            throw new \RuntimeException('Ollama generation returned unparseable text.');
        
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('AI推論エラー: ' . $e->getMessage());
            throw $e; // Throw back so ProcessArticleJob can catch it and invoke fallbacks
        }
    }

    /**
     * AIエージェントへ送信するプロンプトを構築します。
     *
     * @param  array  $categories  カテゴリ一覧
     *                             [['id' => int, 'name' => string, 'parent_name' => ?string], ...]
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

        // App別設定があればそれを使用、なければグローバル設定にフォールバック
        $template = (!empty($app?->ai_prompt_template))
            ? $app->ai_prompt_template
            : Cache::get('ai_prompt_template', $this->getDefaultPromptTemplate());

        $prompt = str_replace(['{categories}', '{title}'], [$categoryList, $originalTitle], $template);

        return $prompt;
    }

    /**
     * デフォルトのプロンプトテンプレート
     */
    private function getDefaultPromptTemplate(): string
    {
        return <<<PROMPT
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
