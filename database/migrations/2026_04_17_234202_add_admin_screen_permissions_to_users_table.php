<?php

use App\Support\AdminScreen;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->json('admin_screen_permissions')
                ->nullable()
                ->after('is_admin');
        });

        DB::table('users')
            ->where('is_admin', true)
            ->update([
                'admin_screen_permissions' => json_encode(AdminScreen::selectableValues(), JSON_UNESCAPED_UNICODE),
            ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('admin_screen_permissions');
        });
    }
};
