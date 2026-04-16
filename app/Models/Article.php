<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class Article extends Model
{
    use HasFactory;
    protected $guarded = [];

    /**
     * JSON シリアライズ時に display_thumbnail_url を自動付与する。
     * API レスポンスでカテゴリのデフォルト画像フォールバックを透過的に提供するため。
     */
    protected $appends = ['display_thumbnail_url'];

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

    /**
     * 表示用サムネイルURLを返すアクセサ。
     *
     * 優先順位:
     * 1. 記事自身の thumbnail_url
     * 2. カテゴリの default_image_path（http 始まりでなければ Storage::url() で変換）
     * 3. null（どちらも未設定）
     */
    public function getDisplayThumbnailUrlAttribute(): ?string
    {
        // ① 自身のサムネイルが存在すればそれを優先
        if (! empty($this->thumbnail_url)) {
            return $this->thumbnail_url;
        }

        // ② カテゴリのデフォルト画像にフォールバック
        $defaultImagePath = $this->category?->default_image_path ?? null;
        if (empty($defaultImagePath)) {
            return null;
        }

        // http(s):// で始まる絶対URLならそのまま返す、ストレージパスなら URL に変換する
        return str_starts_with($defaultImagePath, 'http')
            ? $defaultImagePath
            : Storage::url($defaultImagePath);
    }
}
