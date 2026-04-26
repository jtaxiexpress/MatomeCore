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

    /**
     * 配列形式へ変換します。
     * 古い配列アクセスの構文を使っている箇所との互換性のためのフォールバックです。
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'url' => $this->url,
            'title' => $this->title,
            'image' => $this->image,
            'date' => $this->date,
            'success' => $this->success,
            'errorMessage' => $this->errorMessage,
        ];
    }
}
