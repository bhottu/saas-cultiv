<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sales returns / refunds.
 *
 * A return always references the original sale line (sale_item_id), which carries the
 * historical selling_price and cost_price snapshot — refunds and COGS crediting therefore
 * use the ORIGINAL transaction values, never today's product price.
 *
 * Stock is never edited by hand: every returned unit is recorded as a stock_movement
 * with type "sale_return" by InventoryService (single stock authority).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sale_returns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sale_id')->constrained('sales')->cascadeOnDelete();
            $table->string('return_number', 40);
            $table->timestamp('returned_at');
            $table->unsignedBigInteger('refund_amount')->default(0); // cents (goods value only)
            $table->string('refund_method', 20)->nullable();
            $table->string('reason', 255);
            $table->string('status', 20)->default('completed'); // completed|cancelled
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'return_number']);
            $table->index(['tenant_id', 'sale_id']);
            $table->index(['tenant_id', 'returned_at']);
        });

        Schema::create('sale_return_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sale_return_id')->constrained('sale_returns')->cascadeOnDelete();
            $table->foreignId('sale_item_id')->constrained('sale_items')->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->unsignedInteger('quantity');
            $table->unsignedBigInteger('refund_amount')->default(0); // cents
            $table->timestamps();

            $table->index(['tenant_id', 'sale_return_id']);
            $table->index(['tenant_id', 'sale_item_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sale_return_items');
        Schema::dropIfExists('sale_returns');
    }
};