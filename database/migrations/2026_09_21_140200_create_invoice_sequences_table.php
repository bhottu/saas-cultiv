<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Atomic invoice numbering (works on pgsql; sqlite emulates via unique retry).
        Schema::create('invoice_sequences', function (Blueprint $table) {
            $table->unsignedSmallInteger('year')->primary();
            $table->unsignedBigInteger('last_number')->default(0);
        });

        if (DB::getDriverName() === 'sqlite') {
            return; // sqlite: PaymentService falls back to safe id-based numbering
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_sequences');
    }
};
