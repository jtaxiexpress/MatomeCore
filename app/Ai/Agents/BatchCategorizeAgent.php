<?php

declare(strict_types=1);

namespace App\Ai\Agents;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Attributes\UseCheapestModel;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;

/**
 * バッチ処理用の記事分類・リライトエージェント。
 */
#[UseCheapestModel]
class BatchCategorizeAgent implements Agent, HasStructuredOutput
{
    use Promptable;

    public function instructions(): string
    {
        return <<<'PROMPT'
あなたは日本語のアンテナサイト向けに複数記事の分類とリライトを一括で行う優秀な編集者です。

ユーザーから「カテゴリ一覧」と「処理対象記事の内容」が提供されます。
各記事について以下の2つの処理を行ってください。

1. **カテゴリ分類**: 提供されたカテゴリ一覧の中から最も適切なカテゴリIDを1つ選ぶ。
2. **タイトルリライト**: 元のタイトルを魅力的でクリックしたくなる日本語タイトル（40文字以内）にリライトする。
PROMPT;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'results' => $schema->array()->items(
                $schema->object([
                    'article_id'      => $schema->integer()->required(),
                    'rewritten_title' => $schema->string()->required(),
                    'category_id'     => $schema->integer()->required(),
                ])
            )->required(),
        ];
    }
}
