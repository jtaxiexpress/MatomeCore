<?php

namespace App\Ai\Agents;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Attributes\UseCheapestModel;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;

#[UseCheapestModel]
class CategorizeArticleAgent implements Agent, HasStructuredOutput
{
    use Promptable;

    /**
     * エージェントへの指示（システムプロンプト）。
     * 記事の分類とタイトルリライトを1回で行います。
     */
    public function instructions(): string
    {
        return <<<'PROMPT'
あなたは日本語のアンテナサイト向けに記事の分類とリライトを行う優秀なコピーライターです。
ユーザーから「元のタイトル」と「利用可能なカテゴリ一覧（IDと名前）」が提供されます。

以下の2つのタスクを同時に行い、JSON形式で回答してください：

1. **カテゴリ分類**: 提供された元のタイトルから推測し、カテゴリ一覧の中から最も適切なカテゴリIDを選んでください。
   - 必ず提供されたカテゴリIDの中から選んでください。
   - 最も内容に合致するカテゴリを1つだけ選んでください。

2. **タイトルリライト**: 元のタイトルより魅力的でクリックしたくなるような日本語タイトルにリライトしてください。
   - 内容を正確に伝えつつ、読者の興味を引くタイトルにしてください。
   - 元のタイトルを参考にしながら、より工夫された表現を使ってください。
   - 過度に煽ったり誇張したりせず、自然で読みやすいタイトルにしてください。
   - 40文字以内を目安にしてください。

必ずJSON形式のみで回答してください。余分なテキストは不要です。
PROMPT;
    }

    /**
     * Structured Outputのスキーマ定義。
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'category_id'     => $schema->integer()->required(),
            'rewritten_title' => $schema->string()->required(),
        ];
    }
}
