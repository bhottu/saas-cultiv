<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Create inventory balance/history tables after products exists.
 *
 * The original inventory migration preceded the products migration. PostgreSQL
 * enforces foreign keys immediately, so stock tables are split into this later
 * migration. The hasTable guards also support databases that already ran the
 * original combined inventory migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('stock_balances')) {
            Schema::create('stock_balances', function (Blueprint $table) {
                $table->id();
                $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
                $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
                $table->foreignId('warehouse_id')->constrained('warehouses')->cascadeOnDelete();
                $table->unsignedInteger('quantity')->default(0);
                $table->unsignedInteger('incoming')->default(0);
                $table->unsignedInteger('outgoing')->default(0);
                $table->timestamps();

                $table->unique(['tenant_id', 'product_id', 'warehouse_id']);
                $table->index(['tenant_id', 'quantity']);
            });
        }

        if (! Schema::hasTable('stock_movements')) {
            Schema::create('stock_movements', function (Blueprint $table) {
                $table->id();
                $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
                $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
                $table->foreignId('warehouse_id')->constrained('warehouses')->cascadeOnDelete();
                $table->string('type', 30);
                $table->unsignedInteger('quantity');
                $table->string('reference_type', 30)->nullable();
                $table->unsignedBigInteger('reference_id')->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->text('notes')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamp('created_at')->useCurrent();

                $table->index(['tenant_id', 'product_id', 'created_at']);
                $table->index(['tenant_id', 'warehouse_id', 'created_at']);
                $table->index(['tenant_id', 'type', 'created_at']);
            });
        }
    }

    public function down(): void
    {
        // Intentionally empty: these tables may belong to the original
        // inventory migration on databases that predate this compatibility step.
    }
};