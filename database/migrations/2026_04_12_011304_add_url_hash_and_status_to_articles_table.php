<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('articles', function (Blueprint $table) {
            if (! Schema::hasColumn('articles', 'url_hash')) {
                $table->char('url_hash', 64)->nullable()->unique()->after('url');
            }
            if (! Schema::hasColumn('articles', 'status')) {
                $table->enum('status', ['draft', 'published', 'failed'])->default('draft')->after('url_hash');
            }
            if (! Schema::hasColumn('articles', 'summary')) {
                $table->string('summary')->nullable()->after('title');
            }
            if (! Schema::hasColumn('articles', 'lead_text')) {
                $table->string('lead_text')->nullable()->after('summary');
            }
        });
    }

    public function down(): void
    {
        Schema::table('articles', function (Blueprint $table) {
            $columns = [];
            if (Schema::hasColumn('articles', 'url_hash')) {
                $columns[] = 'url_hash';
            }
            if (Schema::hasColumn('articles', 'status')) {
                $columns[] = 'status';
            }
            if (Schema::hasColumn('articles', 'summary')) {
                $columns[] = 'summary';
            }
            if (Schema::hasColumn('articles', 'lead_text')) {
                $columns[] = 'lead_text';
            }

            if (! empty($columns)) {
                $table->dropColumn($columns);
            }
        });
    }
};
