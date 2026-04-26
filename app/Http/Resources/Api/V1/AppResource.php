<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AppResource extends JsonResource
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
            'name' => $this->name,
            'icon_url' => $this->icon_url,
            'slug' => $this->api_slug,
            'theme_color' => $this->theme_color,
            'links' => [
                'categories' => route('api.v1.apps.categories.index', ['app' => $this->api_slug]),
            ],
        ];
    }
}
