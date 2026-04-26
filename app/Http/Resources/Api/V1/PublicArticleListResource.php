<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PublicArticleListResource extends JsonResource
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
            'title' => filled((string) $this->title) ? $this->title : $this->original_title,
            'url' => $this->url,
            'thumbnail_url' => $this->resolveThumbnailUrl(),
            'site_name' => $this->site?->name,
            'published_at' => $this->published_at?->toISOString(),
            'click_count' => $this->when(isset($this->click_count), (int) $this->click_count),
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
