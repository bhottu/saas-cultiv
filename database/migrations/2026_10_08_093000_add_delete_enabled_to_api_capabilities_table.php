<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Third operation of the API capability matrix: Delete.
 *
 * `create_api_capabilities_table` predates the read/write/delete model, so installs that
 * already ran it are upgraded in place rather than rebuilt (§12 — never destroy existing
 * rows). The column defaults to false, meaning an un-migrated or not-yet-reviewed
 * resource simply cannot be delete-enabled.
 *
 * Values are backfilled from config/api.php so every resource reflects exactly the
 * DELETE endpoints routed today.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('api_capabilities', 'delete_enabled')) {
            return;
        }

        Schema::table('api_capabilities', function (Blueprint $table) {
            $table->boolean('delete_enabled')->default(false)->after('write_enabled');
        });

        foreach ((array) config('api.resources', []) as $key => $definition) {
            DB::table('api_capabilities')
                ->where('resource', $key)
                ->update(['delete_enabled' => (bool) ($definition['delete'] ?? false)]);
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('api_capabilities', 'delete_enabled')) {
            Schema::table('api_capabilities', function (Blueprint $table) {
                $table->dropColumn('delete_enabled');
            });
        }
    }
};