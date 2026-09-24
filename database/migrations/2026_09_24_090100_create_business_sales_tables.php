<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sales documents (POS + back-office).
 *
 * Parent/child design: sales -> sale_items, with payment rows in sale_payments.
 * Every table is tenant-owned so the BelongsToTenant global scope isolates tenants.
 *
 * Money = cents (unsigned), never floats. Historical prices are SNAPSHOTTED on sale_items
 * (selling_price + cost_price) so old invoices/reports never change when a product price
 * changes later — COGS/profit always uses the snapshot.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ---- Sales (document header) ----
        Schema::create('sales', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->foreignId('warehouse_id')->nullable()->constrained('warehouses')->nullOnDelete();
            $table->string('invoice_number', 40);
            // Double-submit guard: the create form posts a one-time reference; a repeated
            // submission with the same reference resolves to the existing sale (never a
            // second stock deduction). NULL is allowed (older/API-created rows).
            $table->string('client_reference', 64)->nullable();
            $table->timestamp('sold_at');

            $table->unsignedBigInteger('subtotal')->default(0);       // Σ net line totals (cents)
            $table->unsignedBigInteger('item_discount')->default(0);  // Σ line discounts (cents)
            $table->unsignedBigInteger('discount')->default(0);       // resolved document discount
            $table->string('discount_type', 10)->default('fixed');    // fixed|percent
            $table->unsignedBigInteger('discount_value')->default(0); // as entered (cents for fixed, whole % for percent)
            $table->unsignedBigInteger('tax')->default(0);            // resolved tax (cents)
            $table->unsignedSmallInteger('tax_percent')->default(0);  // applied rate (configurable, never hard-coded)
            $table->unsignedBigInteger('shipping')->default(0);       // cents
            $table->unsignedBigInteger('total')->default(0);          // cents
            $table->unsignedBigInteger('total_cogs')->default(0);     // Σ qty * cost_price snapshot

            $table->unsignedBigInteger('paid_amount')->default(0);     // Σ sale_payments.amount
            $table->unsignedBigInteger('refunded_amount')->default(0); // Σ sale_returns.refund_amount
            $table->unsignedBigInteger('change_amount')->default(0);   // cash change handed back

            $table->string('status', 20)->default('completed');       // draft|pending|processing|completed|cancelled|refunded
            $table->string('payment_status', 20)->default('unpaid');  // unpaid|partial|paid|refunded
            $table->string('sales_channel', 20)->default('pos');      // pos|online|whatsapp|marketplace|manual
            $table->string('payment_method', 20)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();

            // Idempotency stamps for the inventory side effects (never deduct/revert twice).
            $table->timestamp('stock_applied_at')->nullable();
            $table->timestamp('stock_reverted_at')->nullable();

            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('refunded_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'invoice_number']);
            $table->unique(['tenant_id', 'client_reference']);
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'payment_status']);
            $table->index(['tenant_id', 'sold_at']);
            $table->index(['tenant_id', 'customer_id', 'sold_at']);
            $table->index(['tenant_id', 'sales_channel']);
        });

        // ---- Sale items (line items, price snapshots) ----
        Schema::create('sale_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sale_id')->constrained('sales')->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->string('product_name');         // snapshot: the invoice stays readable forever
            $table->string('sku', 60)->nullable();  // snapshot
            $table->string('unit', 30)->default('pcs');
            $table->unsignedInteger('quantity');
            $table->unsignedBigInteger('selling_price'); // cents snapshot
            $table->unsignedBigInteger('cost_price');    // cents snapshot (COGS source of truth)
            $table->string('discount_type', 10)->default('fixed');
            $table->unsignedBigInteger('discount_value')->default(0);
            $table->unsignedBigInteger('discount')->default(0);  // resolved line discount (cents)
            $table->unsignedBigInteger('subtotal')->default(0);  // (quantity * selling_price) - discount
            $table->unsignedInteger('returned_quantity')->default(0);
            $table->timestamps();

            $table->index(['tenant_id', 'sale_id']);
            $table->index(['tenant_id', 'product_id']);
        });

        // ---- Sale payments ----
        Schema::create('sale_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sale_id')->constrained('sales')->cascadeOnDelete();
            $table->string('method', 20); // cash|bank_transfer|qris|debit_card|credit_card|ewallet|other
            $table->unsignedBigInteger('amount');                 // cents
            $table->unsignedBigInteger('change_amount')->default(0);
            $table->string('reference', 100)->nullable();
            $table->timestamp('paid_at');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'sale_id']);
            $table->index(['tenant_id', 'paid_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sale_payments');
        Schema::dropIfExists('sale_items');
        Schema::dropIfExists('sales');
    }
};