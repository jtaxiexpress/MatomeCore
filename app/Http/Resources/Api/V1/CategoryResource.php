<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

class CategoryResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $defaultImagePath = (string) ($this->default_image_path ?? '');
        $defaultImageUrl = null;

        if ($defaultImagePath !== '') {
            $defaultImageUrl = str_starts_with($defaultImagePath, 'http')
                ? $defaultImagePath
                : Storage::url($defaultImagePath);
        }

        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->api_slug,
            'sort_order' => (int) $this->sort_order,
            'default_image_url' => $defaultImageUrl,
            'children' => CategoryResource::collection($this->whenLoaded('children')),
        ];
    }
}
