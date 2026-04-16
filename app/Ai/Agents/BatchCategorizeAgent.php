<?php

declare(strict_types=1);

namespace App\Ai\Agents;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Attributes\Provider;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;

/**
 * バッチ処理用の記事分類・リライトエージェント。
 */
#[Provider('gemini')]
class BatchCategorizeAgent implements Agent, HasStructuredOutput
{
    use Promptable;

    public function instructions(): string
    {
        return '';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'results' => $schema->array()->items(
                $schema->object([
                    'article_id' => $schema->integer()->required(),
                    'rewritten_title' => $schema->string()->required(),
                    'category_id' => $schema->integer()->required(),
                ])
            )->required(),
        ];
    }
}
