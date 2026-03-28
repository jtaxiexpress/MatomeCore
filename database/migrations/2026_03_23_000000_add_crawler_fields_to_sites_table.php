<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->string('crawler_type')->default('html');
            $table->string('sitemap_url')->nullable();
            $table->string('crawl_start_url')->nullable();
            $table->string('list_item_selector')->nullable();
            $table->string('link_selector')->nullable();
            $table->string('title_selector')->nullable();
            $table->string('thumbnail_selector')->nullable();
            $table->string('date_selector')->nullable();
            $table->string('next_page_selector')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->dropColumn([
                'crawler_type',
                'sitemap_url',
                'crawl_start_url',
                'list_item_selector',
                'link_selector',
                'title_selector',
                'thumbnail_selector',
                'date_selector',
                'next_page_selector',
            ]);
        });
    }
};
