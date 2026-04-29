<?php

declare(strict_types=1);

namespace App\DTOs;

use ArrayAccess;
use Illuminate\Contracts\Support\Arrayable;
use InvalidArgumentException;

readonly class AiAnalyzedData implements Arrayable, ArrayAccess
{
    public function __construct(
        public int $categoryId,
        public string $rewrittenTitle,
    ) {}

    public function toArray(): array
    {
        return [
            'categoryId' => $this->categoryId,
            'rewrittenTitle' => $this->rewrittenTitle,
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
