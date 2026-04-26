<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('articles', function (Blueprint $table) {
            // [実装の意図]
            // 大規模データ保持において、記事の最新一覧取得や人気順取得（フィルタリング＆ソート）を高速化するため、
            // 検索・絞り込みで頻繁に使用される published_at, status, daily_out_count を組み合わせた複合インデックスを追加します。
            $table->index(['published_at', 'status', 'daily_out_count'], 'articles_pub_stat_out_idx');

            /*
             * [リファクタリングの提案: url_hashによるupsert活用について]
             * 既存記事の再取得時にDB負荷を最小限に抑えるためには、
             * 記事保存のロジックにおいて以下のような `upsert` を活用するアプローチが有効です。
             *
             * Article::upsert(
             *     $articlesDataArray, // 保存する記事データの配列
             *     ['url_hash'],       // 重複チェックに利用するユニークキー
             *     ['title', 'thumbnail_url', 'updated_at'] // 既に存在した場合に更新するカラム
             * );
             *
             * url_hashは固定長でインデックス効率が良く、重複チェックをPHP側で行う代わりに
             * DB側で一括処理させることで、クエリ発行数を大幅に削減し、
             * ピーク時のパフォーマンス改善と大規模データ保持に耐えうる負荷軽減が可能になります。
             */
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('articles', function (Blueprint $table) {
            $table->dropIndex('articles_pub_stat_out_idx');
        });
    }
};
