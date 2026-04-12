<?php

declare(strict_types=1);

namespace App\Ai\Agents;

use Laravel\Ai\Attributes\UseCheapestModel;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Promptable;

/**
 * バッチ処理用の記事分類・リライトエージェント。
 *
 * CategorizeArticleAgent と異なり HasStructuredOutput を実装せず、
 * JSON配列文字列をそのままテキストとして返させます。
 */
#[UseCheapestModel]
class BatchCategorizeAgent implements Agent
{
    use Promptable;

    public function instructions(): string
    {
        return <<<'PROMPT'
あなたは日本語のアンテナサイト向けに複数記事の分類とリライトを一括で行う優秀な編集者です。

ユーザーから「カテゴリ一覧」と「処理対象記事のJSON」が提供されます。
各記事について以下の2つを行い、結果をJSON配列形式で返してください。

1. **カテゴリ分類**: 提供されたカテゴリ一覧の中から最も適切なカテゴリIDを1つ選ぶ。
2. **タイトルリライト**: 元のタイトルを魅力的でクリックしたくなる日本語タイトル（40文字以内）にリライトする。

**出力形式**: 必ず以下のJSON配列のみで回答してください。マークダウン、コードブロック、解説文は一切含めないでください。

[{"article_id": 1, "rewritten_title": "新しいタイトル", "category_id": 3}]
PROMPT;
    }
}
