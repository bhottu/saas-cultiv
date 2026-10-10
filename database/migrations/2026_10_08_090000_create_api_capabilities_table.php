<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Platform-global API capability configuration (/admin/api).
 *
 * One row per API resource. Deliberately NO tenant_id: this is platform configuration,
 * not workspace data, so no workspace can read or write another's capability state.
 * The `resource` unique constraint guarantees a single configuration per resource.
 *
 * Rows are seeded from config('api.resources') so a fresh install starts with exactly
 * the surface the API exposes today: Read available everywhere, Write only for the
 * resources that really have write endpoints.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_capabilities', function (Blueprint $table) {
            $table->id();
            $table->string('resource', 64)->unique();
            $table->boolean('read_enabled')->default(false);
            $table->boolean('write_enabled')->default(false);
            $table->boolean('delete_enabled')->default(false);
            $table->timestamps();
        });

        $now = now();

        foreach ((array) config('api.resources', []) as $key => $definition) {
            DB::table('api_capabilities')->insert([
                'resource' => $key,
                'read_enabled' => true,
                'write_enabled' => (bool) ($definition['write'] ?? false),
                'delete_enabled' => (bool) ($definition['delete'] ?? false),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('api_capabilities');
    }
};