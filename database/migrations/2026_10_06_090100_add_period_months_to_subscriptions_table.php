<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * How many months ONE paid period covers.
 *
 * A checkout may prepay 1, 3, 6 or 12 months of the monthly price, so a subscription
 * whose billing_cycle says "monthly" can be worth three months of money. Renewal and
 * MRR both need the divisor: renew() extends the period by period_months (not blindly
 * by one month), and the admin dashboard counts amount / period_months so a 3-month
 * prepayment is one third per month instead of inflating MRR threefold.
 *
 * The backfill gives every existing yearly row 12 so renewal keeps adding a year; every
 * other pre-existing row was a single monthly payment, so 1 is exact for them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->unsignedSmallInteger('period_months')->default(1)->after('billing_cycle');
        });

        DB::table('subscriptions')->where('billing_cycle', 'yearly')->update(['period_months' => 12]);
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropColumn('period_months');
        });
    }
};