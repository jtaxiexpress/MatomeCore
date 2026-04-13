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
            $table->dropColumn([
                'gemini_model',
                'ollama_model',
                'ollama_num_predict',
                'ollama_num_ctx',
            ]);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('apps', function (Blueprint $table) {
            $table->string('gemini_model')->nullable();
            $table->string('ollama_model')->nullable();
            $table->integer('ollama_num_predict')->nullable();
            $table->integer('ollama_num_ctx')->nullable();
        });
    }
};
