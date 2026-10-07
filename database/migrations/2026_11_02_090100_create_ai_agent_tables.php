<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_settings', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 20)->default('openai');
            $table->string('model', 120)->default('gpt-4o-mini');
            $table->string('fallback_provider', 20)->nullable();
            $table->string('fallback_model', 120)->nullable();
            $table->text('openai_api_key')->nullable();
            $table->text('gemini_api_key')->nullable();
            $table->string('ollama_base_url', 255)->default('http://127.0.0.1:11434');
            $table->text('telegram_bot_token')->nullable();
            $table->text('telegram_webhook_secret')->nullable();
            $table->timestamps();
        });

        Schema::create('ai_channel_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('channel', 20)->default('telegram');
            $table->string('external_id', 120)->nullable();
            $table->string('link_token_hash', 64)->nullable()->unique();
            $table->timestamp('link_expires_at')->nullable();
            $table->timestamp('linked_at')->nullable();
            $table->timestamps();

            $table->unique(['channel', 'external_id']);
            $table->index(['tenant_id', 'user_id', 'channel']);
        });

        Schema::create('ai_pending_actions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('channel_link_id')->constrained('ai_channel_links')->cascadeOnDelete();
            $table->string('action', 60);
            $table->json('payload');
            $table->string('status', 20)->default('pending');
            $table->timestamp('expires_at');
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'user_id', 'status']);
        });

        Schema::create('ai_channel_updates', function (Blueprint $table) {
            $table->id();
            $table->string('channel', 20);
            $table->string('external_update_id', 80);
            $table->timestamps();

            $table->unique(['channel', 'external_update_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_channel_updates');
        Schema::dropIfExists('ai_pending_actions');
        Schema::dropIfExists('ai_channel_links');
        Schema::dropIfExists('ai_settings');
    }
};
