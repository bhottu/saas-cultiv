<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Store manual per-period prices (1 / 3 / 6 / 12 months) instead of defaulting to
     * monthly_price x months.
     *
     * A column value of 0 means "not set manually", so the price falls back to
     * monthly_price x months (or price_yearly, for the 12-month cycle).
     */
    public function up(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->unsignedInteger('price_1month')->nullable()->default(0)->after('price_yearly');
            $table->unsignedInteger('price_3months')->nullable()->default(0)->after('price_1month');
            $table->unsignedInteger('price_6months')->nullable()->default(0)->after('price_3months');
            $table->unsignedInteger('price_12months')->nullable()->default(0)->after('price_6months');
        });
    }

    public function down(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->dropColumn([
                'price_1month',
                'price_3months',
                'price_6months',
                'price_12months',
            ]);
        });
    }
};

