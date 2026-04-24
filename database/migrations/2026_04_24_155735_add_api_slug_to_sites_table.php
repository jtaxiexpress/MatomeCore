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
        Schema::table('sites', function (Blueprint $table) {
            $table->string('api_slug')->nullable()->after('name');
        });

        $this->backfillSiteSlugs();

        Schema::table('sites', function (Blueprint $table) {
            $table->unique('api_slug', 'sites_api_slug_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->dropUnique('sites_api_slug_unique');
            $table->dropColumn('api_slug');
        });
    }

    private function backfillSiteSlugs(): void
    {
        $used = [];

        $sites = DB::table('sites')
            ->select(['id', 'name', 'api_slug'])
            ->orderBy('id')
            ->cursor();

        foreach ($sites as $site) {
            $base = Str::slug((string) ($site->api_slug ?: $site->name));
            $base = $base !== '' ? $base : 'site-'.$site->id;

            $slug = $base;
            $suffix = 2;

            while (in_array($slug, $used, true)) {
                $slug = $base.'-'.$suffix;
                $suffix++;
            }

            DB::table('sites')
                ->where('id', $site->id)
                ->update(['api_slug' => $slug]);

            $used[] = $slug;
        }
    }
};
