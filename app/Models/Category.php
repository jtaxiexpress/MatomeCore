<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Category extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (Category $category): void {
            $category->api_slug = static::resolveUniqueApiSlug(
                value: (string) ($category->api_slug ?: $category->name),
                appId: (int) ($category->app_id ?: 0),
                ignoreId: $category->id,
            );
        });
    }

    private static function resolveUniqueApiSlug(string $value, int $appId, ?int $ignoreId = null): string
    {
        $base = Str::slug($value);
        $base = $base !== '' ? $base : 'category';

        $slug = $base;
        $suffix = 2;

        while (static::query()
            ->where('app_id', $appId)
            ->when($ignoreId, fn (Builder $query): Builder => $query->whereKeyNot($ignoreId))
            ->where('api_slug', $slug)
            ->exists()) {
            $slug = $base.'-'.$suffix;
            $suffix++;
        }

        return $slug;
    }

    public function app(): BelongsTo
    {
        return $this->belongsTo(App::class);
    }

    // ────────────────────────────────
    // 自己参照リレーション（階層構造）
    // ────────────────────────────────

    /**
     * 親カテゴリを取得します。
     * ルートカテゴリの場合は null を返します。
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'parent_id');
    }

    /**
     * 子カテゴリ（サブカテゴリ）一覧を取得します。
     * APIで with('children') としてツリー構造のJSONを返す際に使用します。
     */
    public function children(): HasMany
    {
        return $this->hasMany(Category::class, 'parent_id');
    }

    // ────────────────────────────────
    // その他のリレーション
    // ────────────────────────────────

    public function articles(): HasMany
    {
        return $this->hasMany(Article::class);
    }
}
