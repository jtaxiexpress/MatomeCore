<?php

use App\Models\Category;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * カテゴリの親子関係（自己参照）を実現するため、
     * categories テーブルに parent_id カラムを追加します。
     */
    public function up(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->foreignId('parent_id')
                ->nullable()                      // ルートカテゴリ（親なし）を許可
                ->after('app_id')
                ->constrained('categories')       // 自己参照の外部キー制約
                ->cascadeOnDelete();              // 親削除時に子カテゴリも連動削除
        });
    }

    /**
     * マイグレーションをロールバックします。
     */
    public function down(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->dropForeignIdFor(Category::class, 'parent_id');
            $table->dropColumn('parent_id');
        });
    }
};
