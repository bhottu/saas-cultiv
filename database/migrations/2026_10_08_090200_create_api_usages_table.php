<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-request API usage log (§23).
 *
 * Scoped by tenant_id so a workspace's usage can only ever be read by that workspace.
 * The raw token/Authorization header is NEVER stored — only the token's database id,
 * which is useless as a credential.
 *
 * Append-only: created_at only, no updated_at (a request never changes after it
 * happened), hence ApiUsage::UPDATED_AT = null.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_usages', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('personal_access_token_id')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('method', 10);
            $table->string('path', 255);
            $table->unsignedSmallInteger('status_code');
            $table->unsignedInteger('duration_ms')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['tenant_id', 'created_at']);
            $table->index('personal_access_token_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_usages');
    }
};