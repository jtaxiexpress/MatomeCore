<?php

declare(strict_types=1);

namespace App\DTOs;

readonly class AiAnalyzedData
{
    public function __construct(
        public int $categoryId,
        public string $rewrittenTitle,
    ) {}
}
