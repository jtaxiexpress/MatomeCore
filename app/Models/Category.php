<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use SolutionForest\FilamentTree\Concern\ModelTree;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
