<?php

declare(strict_types=1);

namespace App\DTOs;

use ArrayAccess;
use Illuminate\Contracts\Support\Arrayable;
use InvalidArgumentException;

readonly class ScrapedArticleData implements ArrayAccess, Arrayable
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

    public function offsetExists(mixed $offset): bool
    {
        return property_exists($this, $offset);
    }

    public function offsetGet(mixed $offset): mixed
    {
        if (! property_exists($this, $offset)) {
            throw new InvalidArgumentException("Undefined property: {$offset}");
        }

        return $this->{$offset};
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        throw new InvalidArgumentException('Cannot mutate readonly DTO.');
    }

    public function offsetUnset(mixed $offset): void
    {
        throw new InvalidArgumentException('Cannot mutate readonly DTO.');
    }

    public function __get(string $name)
    {
        if (! property_exists($this, $name)) {
            throw new InvalidArgumentException("Undefined property: {$name}");
        }

        return $this->{$name};
    }
}
