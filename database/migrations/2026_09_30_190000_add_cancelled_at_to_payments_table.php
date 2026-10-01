<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Records when a customer abandoned an unsettled payment by choosing "Batalkan & Buat
 * Baru" on the billing page.
 *
 * The row itself is never deleted — the payment and its invoice stay in the history, and
 * only the status changes to "cancelled". This column is the audit trail for that
 * decision, matching the existing `paid_at` convention on the same table.
 *
 * Additive and nullable: existing rows (including the ones already pending in
 * production) are untouched, so the migration is safe to run on a live database.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('payments', 'cancelled_at')) {
            return;
        }

        Schema::table('payments', function (Blueprint $table) {
            $table->timestamp('cancelled_at')->nullable()->after('paid_at');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('payments', 'cancelled_at')) {
            return;
        }

        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn('cancelled_at');
        });
    }
};
