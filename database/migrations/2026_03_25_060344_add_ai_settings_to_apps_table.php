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
        Schema::table('apps', function (Blueprint $table) {
            $table->text('ai_prompt_template')->nullable()->after('is_active');
            $table->string('ollama_model')->nullable()->after('ai_prompt_template');
            $table->string('gemini_model')->nullable()->after('ollama_model');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('apps', function (Blueprint $table) {
            $table->dropColumn(['ai_prompt_template', 'ollama_model', 'gemini_model']);
        });
    }
};
