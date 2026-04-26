<?php

declare(strict_types=1);

namespace App\DTOs;

readonly class ScrapedArticleData
{
    public function __construct(
        public string $url,
        public ?string $title = null,
        public ?string $image = null,
        public ?string $date = null,
        public bool $success = true,
        public ?string $errorMessage = null,
    ) {}
}
