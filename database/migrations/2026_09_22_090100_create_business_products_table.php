<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        // ---- Products (tenant-owned inventory items) ----
        // Historical prices live on sale_items / purchase_items line items.
        // This table holds the CURRENT purchase/selling price only.
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('category_id')->nullable()->constrained('categories')->nullOnDelete();
            $table->foreignId('brand_id')->nullable()->constrained('brands')->nullOnDelete();
            $table->string('sku', 60)->nullable();
            $table->string('barcode', 100)->nullable();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('unit', 30)->default('pcs');     // pcs|kg|liter|box|set
            $table->unsignedInteger('purchase_price')->default(0);  // cents, current cost
            $table->unsignedInteger('selling_price')->default(0);   // cents, current price
            $table->unsignedInteger('cost_price')->default(0);       // cents, for reports/AVCO
            $table->unsignedInteger('minimum_stock')->default(0);
            $table->unsignedInteger('max_stock')->nullable();
            $table->boolean('track_inventory')->default(true);
            $table->boolean('is_active')->default(true);
            $table->string('image')->nullable();           // storage path, optional
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'sku'], 'products_tenant_sku_unique');
            $table->unique(['tenant_id', 'barcode'], 'products_tenant_barcode_unique');
            $table->index(['tenant_id', 'is_active']);
            $table->index(['tenant_id', 'category_id']);
            $table->index(['tenant_id', 'name']);

            // SQLite FK enforcement during tests.
            $table->foreign('category_id')->references('id')->on('categories');
            $table->foreign('brand_id')->references('id')->on('brands');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};