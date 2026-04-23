<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

class Article extends Model
{
    use HasFactory;

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

    public function clicks(): HasMany
    {
        return $this->hasMany(ArticleClick::class);
    }

    /**
     * スコアが著しく低いサイト（IN比率が極端に低くOUTが多い）の記事を除外するスコープ
     */
    public function scopeTrafficFiltered($query, int $minScore = -100)
    {
        return $query->whereHas('site', function ($q) use ($minScore) {
            $q->where('traffic_score', '>=', $minScore)
                ->orWhere('is_active', false); // Optional: depends on how inactive sites are handled, but usually we only query active sites anyway.
        });
    }

    /**
     * 新着かつサイトスコア（勢い）を加味した独自のソート
     * daily_out_countを基準にする、またはシンプルに新しい順
     */
    public function scopeTrending($query)
    {
        return $query->orderByDesc('daily_out_count')
            ->orderByDesc('published_at');
    }

    /**
     * 表示用サムネイルURLを返すアクセサ。
     *
     * 優先順位:
     * 1. 記事自身の thumbnail_url
     * 2. カテゴリの default_image_path（http 始まりでなければ Storage::url() で変換）
     * 3. null（どちらも未設定）
     */
    protected function displayThumbnailUrl(): Attribute
    {
        return Attribute::make(
            get: function (): ?string {
                if (! empty($this->thumbnail_url)) {
                    return $this->thumbnail_url;
                }

                $category = $this->getRelationValue('category');

                if (! $category instanceof Category) {
                    return null;
                }

                $defaultImagePath = $category->default_image_path;

                if (empty($defaultImagePath)) {
                    return null;
                }

                return str_starts_with($defaultImagePath, 'http')
                    ? $defaultImagePath
                    : Storage::url($defaultImagePath);
            },
        );
    }
}
