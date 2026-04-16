<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! Schema::hasColumn('apps', 'api_slug')) {
            Schema::table('apps', function (Blueprint $table) {
                $table->string('api_slug')->nullable()->after('name');
            });
        }

        if (! Schema::hasColumn('categories', 'api_slug')) {
            Schema::table('categories', function (Blueprint $table) {
                $table->string('api_slug')->nullable()->after('name');
            });
        }

        $this->backfillAppSlugs();
        $this->backfillCategorySlugs();

        Schema::table('apps', function (Blueprint $table) {
            $table->unique('api_slug', 'apps_api_slug_unique');
        });

        Schema::table('categories', function (Blueprint $table) {
            $table->unique(['app_id', 'api_slug'], 'categories_app_id_api_slug_unique');
        });

        Schema::table('articles', function (Blueprint $table) {
            $table->index(['app_id', 'category_id', 'published_at'], 'articles_app_category_published_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('articles', function (Blueprint $table) {
            $table->dropIndex('articles_app_category_published_idx');
        });

        Schema::table('categories', function (Blueprint $table) {
            $table->dropUnique('categories_app_id_api_slug_unique');
        });

        Schema::table('apps', function (Blueprint $table) {
            $table->dropUnique('apps_api_slug_unique');
        });

        if (Schema::hasColumn('categories', 'api_slug')) {
            Schema::table('categories', function (Blueprint $table) {
                $table->dropColumn('api_slug');
            });
        }

        if (Schema::hasColumn('apps', 'api_slug')) {
            Schema::table('apps', function (Blueprint $table) {
                $table->dropColumn('api_slug');
            });
        }
    }

    private function backfillAppSlugs(): void
    {
        $used = [];

        $apps = DB::table('apps')
            ->select(['id', 'name', 'api_slug'])
            ->orderBy('id')
            ->get();

        foreach ($apps as $app) {
            $base = Str::slug((string) ($app->api_slug ?: $app->name));
            $base = $base !== '' ? $base : 'app-'.$app->id;

            $slug = $base;
            $suffix = 2;

            while (in_array($slug, $used, true)) {
                $slug = $base.'-'.$suffix;
                $suffix++;
            }

            DB::table('apps')
                ->where('id', $app->id)
                ->update(['api_slug' => $slug]);

            $used[] = $slug;
        }
    }

    private function backfillCategorySlugs(): void
    {
        $usedByApp = [];

        $categories = DB::table('categories')
            ->select(['id', 'app_id', 'name', 'api_slug'])
            ->orderBy('app_id')
            ->orderBy('id')
            ->get();

        foreach ($categories as $category) {
            $appId = (int) $category->app_id;

            if (! isset($usedByApp[$appId])) {
                $usedByApp[$appId] = [];
            }

            $base = Str::slug((string) ($category->api_slug ?: $category->name));
            $base = $base !== '' ? $base : 'category-'.$category->id;

            $slug = $base;
            $suffix = 2;

            while (in_array($slug, $usedByApp[$appId], true)) {
                $slug = $base.'-'.$suffix;
                $suffix++;
            }

            DB::table('categories')
                ->where('id', $category->id)
                ->update(['api_slug' => $slug]);

            $usedByApp[$appId][] = $slug;
        }
    }
};
