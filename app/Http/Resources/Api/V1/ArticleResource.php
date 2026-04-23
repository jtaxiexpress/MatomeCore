<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ArticleResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'summary' => $this->summary,
            'lead_text' => $this->lead_text,
            'url' => $this->url,
            'thumbnail_url' => $this->resolveThumbnailUrl(),
            'fetch_source' => $this->fetch_source,
            'published_at' => $this->published_at?->toISOString(),
        ];
    }

    private function resolveThumbnailUrl(): ?string
    {
        if (is_string($this->thumbnail_url) && $this->thumbnail_url !== '') {
            return $this->thumbnail_url;
        }

        if (! $this->resource->relationLoaded('category')) {
            return null;
        }

        return $this->display_thumbnail_url;
    }
}
