<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Article extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'published_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Article $article): void {
            if (empty($article->url_hash) && ! empty($article->url)) {
                $article->url_hash = hash('sha256', $article->url);
            }
        });
    }

    public function app(): BelongsTo
    {
        return $this->belongsTo(App::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }
}
