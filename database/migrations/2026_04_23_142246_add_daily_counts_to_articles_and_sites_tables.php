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
            $table->unsignedInteger('daily_out_count')->default(0)->after('status')->index();
        });

        Schema::table('sites', function (Blueprint $table) {
            $table->unsignedInteger('daily_in_count')->default(0)->after('is_active')->index();
            $table->unsignedInteger('daily_out_count')->default(0)->after('daily_in_count')->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->dropColumn(['daily_in_count', 'daily_out_count']);
        });

        Schema::table('articles', function (Blueprint $table) {
            $table->dropColumn('daily_out_count');
        });
    }
};
