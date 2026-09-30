<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expense_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['tenant_id', 'name']);
        });

        Schema::create('expenses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('category_id')->nullable()->constrained('expense_categories')->nullOnDelete();
            $table->string('description');
            $table->unsignedBigInteger('amount');
            $table->date('expense_date');
            $table->string('payment_method', 20)->default('cash');
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['tenant_id', 'expense_date']);
            $table->index(['tenant_id', 'category_id']);
        });

        Schema::create('business_invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('invoice_number', 40);
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('supplier_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('sale_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('purchase_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 20)->default('open');
            $table->unsignedBigInteger('subtotal')->default(0);
            $table->unsignedBigInteger('discount')->default(0);
            $table->unsignedBigInteger('tax')->default(0);
            $table->unsignedBigInteger('shipping')->default(0);
            $table->unsignedBigInteger('total')->default(0);
            $table->unsignedBigInteger('amount_paid')->default(0);
            $table->unsignedBigInteger('outstanding')->default(0);
            $table->timestamp('issued_at');
            $table->date('due_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['tenant_id', 'invoice_number']);
            $table->index(['tenant_id', 'status', 'issued_at']);
            $table->index(['tenant_id', 'customer_id']);
            $table->index(['tenant_id', 'supplier_id']);
        });

        Schema::create('business_invoice_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('business_invoice_id')->constrained()->cascadeOnDelete();
            $table->string('method', 30);
            $table->unsignedBigInteger('amount');
            $table->string('reference', 100)->nullable();
            $table->timestamp('paid_at');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'business_invoice_id', 'paid_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('business_invoice_payments');
        Schema::dropIfExists('business_invoices');
        Schema::dropIfExists('expenses');
        Schema::dropIfExists('expense_categories');
    }
};
