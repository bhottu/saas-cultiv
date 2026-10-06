<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The single row of platform-wide payment gateway selection (payment_settings).
 *
 * One row by convention — the same shape seo_settings uses — so there is nothing to
 * select by and no read path can ever see two conflicting values. The column default
 * and the model's fallback both say "qrispw": a fresh install (or a missing row)
 * behaves exactly like the single-gateway release, and Kasera is only ever used once
 * an administrator has explicitly chosen it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_settings', function (Blueprint $table) {
            $table->id();
            $table->string('active_gateway', 30)->default('qrispw');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_settings');
    }
};