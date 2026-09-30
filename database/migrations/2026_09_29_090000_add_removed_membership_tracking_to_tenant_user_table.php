<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Membership history for team removal (soft delete on tenant_user).
 *
 * Removing a team member must NOT delete the user account and must NOT erase the
 * fact that the person was once part of the workspace. With SoftDeletes the row is
 * kept (status = 'removed' + deleted_at) so that:
 *  - every existing relation (User::memberships, Tenant::memberships, seatCount,
 *    membershipIn) automatically treats the row as a non-member,
 *  - audit/history stays accurate and the Owner can see / restore who was removed,
 *  - the unique (tenant_id, user_id) pair stays reserved, so a later re-invite
 *    revives the same row instead of violating the index.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_user', function (Blueprint $table) {
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::table('tenant_user', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
    }
};
