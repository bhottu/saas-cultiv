<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-tenant document numbering (INV-PUR-RTR).
 *
 * Why a new table instead of extending invoice_sequences:
 *  - invoice_sequences is the SaaS-billing counter: PRIMARY KEY (year) only, so it physically
 *    cannot hold one sequence per document type. PaymentService upserts on ["year"], so adding
 *    a "type" column would break its ON CONFLICT target on PostgreSQL. It stays untouched.
 *  - Sales documents need readable, tenant-scoped, daily-resetting numbers
 *    (e.g. INV-20260924-0001) exactly as DocumentNumberingService already promised.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_sequences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('type', 30);   // sale|sale_return|purchase|invoice
            $table->string('period', 10); // YYYYMMDD (daily) or YYYY (yearly)
            $table->unsignedBigInteger('last_number')->default(0);
            $table->timestamps();

            $table->unique(['tenant_id', 'type', 'period']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_sequences');
    }
};