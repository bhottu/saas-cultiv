<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_settings', function (Blueprint $table) {
            // OpenRouter credential, encrypted at rest via the model cast exactly like the
            // OpenAI and Gemini keys. Nullable so installations that never pick OpenRouter
            // keep a NULL here. No ->after(): it is MySQL-only and SQLite (the test driver)
            // ignores it; column order is functionally irrelevant.
            $table->text('openrouter_api_key')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('ai_settings', function (Blueprint $table) {
            $table->dropColumn('openrouter_api_key');
        });
    }
};
