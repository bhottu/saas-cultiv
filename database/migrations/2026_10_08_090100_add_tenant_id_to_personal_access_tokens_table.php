<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bind every API token to exactly ONE workspace.
 *
 * Before this column the tenant was resolved from the owner's `current_tenant_id`,
 * which meant a token silently changed workspace whenever its owner switched — the
 * opposite of the guarantee an integration credential must give (§2: a Workspace A
 * token can never read or write Workspace B).
 *
 * Nullable on purpose: rows created before this migration are legacy credentials and
 * keep the old (membership-validated) resolution until they are rotated. New tokens
 * always receive a tenant_id at creation time.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->unsignedBigInteger('tenant_id')->nullable();
            $table->index('tenant_id');
        });
    }

    public function down(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->dropIndex(['tenant_id']);
            $table->dropColumn('tenant_id');
        });
    }
};