<?php

namespace App\Ai\Agents;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Attributes\Provider;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;

#[Provider('gemini')]
class CategorizeArticleAgent implements Agent, HasStructuredOutput
{
    use Promptable;

    /**
     * エージェントへの指示（システムプロンプト）。
     * 記事の分類とタイトルリライトを1回で行います。
     */
    public function instructions(): string
    {
        return '';
    }

    /**
     * Structured Outputのスキーマ定義。
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'category_id' => $schema->integer()->required(),
            'rewritten_title' => $schema->string()->required(),
        ];
    }
}
