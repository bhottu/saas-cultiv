<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Widen every 32-bit money column to BIGINT (non-destructive).
 *
 * Money is stored as integers (App\Services\Money): business tables in CENTS,
 * SaaS billing tables in WHOLE RUPIAH. A signed 32-bit integer tops out at
 * 2.147.483.647 — only Rp 21.474.836,47 of cents — so a product priced at
 * Rp 25.000.000 writes 2.500.000.000 cents and overflows with
 * "Input value 2500000000 is out of range". MySQL's unsigned INT (4.29B)
 * happened to hide this; PostgreSQL enforces the limit strictly.
 *
 * integer -> bigint is a pure widening ALTER: every existing row keeps its exact
 * value, so there is no backfill and no change to any report/ledger figure.
 * down() restores the narrower type only as long as nothing exceeds 32 bits.
 *
 * Already BIGINT (left untouched): sale_items.*, sale_returns.refund_amount,
 * purchase_items.*, sale_payments.amount, expenses.amount, files.size.
 * Intentionally NOT money, so also left untouched: quantities/stock levels,
 * sort_order, timestamps and cache/job counters — all comfortably inside int32.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Business products — stored in CENTS (current purchase/selling/AVCO cost).
        Schema::table('products', function (Blueprint $table) {
            $table->unsignedBigInteger('purchase_price')->default(0)->change();
            $table->unsignedBigInteger('selling_price')->default(0)->change();
            $table->unsignedBigInteger('cost_price')->default(0)->change();
        });

        // SaaS core — stored in WHOLE RUPIAH (plan pricing, subscription amount).
        Schema::table('plans', function (Blueprint $table) {
            $table->unsignedBigInteger('price_monthly')->default(0)->change();
            $table->unsignedBigInteger('price_yearly')->default(0)->change();
        });

        Schema::table('subscriptions', function (Blueprint $table) {
            $table->unsignedBigInteger('amount')->default(0)->change();
        });

        // SaaS billing — WHOLE RUPIAH, NOT NULL without a default: restate exactly
        // that (no ->default(), no ->nullable()) so change() alters nothing else.
        Schema::table('invoices', function (Blueprint $table) {
            $table->unsignedBigInteger('amount')->change();
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->unsignedBigInteger('amount')->change();
        });

        // Module catalogue pricing metadata (whole rupiah; never moves money).
        Schema::table('modules', function (Blueprint $table) {
            $table->unsignedBigInteger('price_monthly')->default(0)->change();
            $table->unsignedBigInteger('price_yearly')->default(0)->change();
        });
    }

    public function down(): void
    {
        // Lossless in reverse only while every stored value fits in 32 bits.
        Schema::table('products', function (Blueprint $table) {
            $table->unsignedInteger('purchase_price')->default(0)->change();
            $table->unsignedInteger('selling_price')->default(0)->change();
            $table->unsignedInteger('cost_price')->default(0)->change();
        });

        Schema::table('plans', function (Blueprint $table) {
            $table->unsignedInteger('price_monthly')->default(0)->change();
            $table->unsignedInteger('price_yearly')->default(0)->change();
        });

        Schema::table('subscriptions', function (Blueprint $table) {
            $table->unsignedInteger('amount')->default(0)->change();
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->unsignedInteger('amount')->change();
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->unsignedInteger('amount')->change();
        });

        Schema::table('modules', function (Blueprint $table) {
            $table->unsignedInteger('price_monthly')->default(0)->change();
            $table->unsignedInteger('price_yearly')->default(0)->change();
        });
    }
};