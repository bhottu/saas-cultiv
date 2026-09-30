<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Module management (marketplace + per-tenant activation).
 *
 * Two tables, two owners — deliberately NOT one table with a nullable tenant_id:
 *
 *  - `modules` is the PLATFORM catalogue (same ownership as `plans`): what Cultiv One
 *    ships. It is NOT tenant-owned, so it carries no tenant_id and no tenant scope.
 *  - `tenant_modules` is the TENANT-owned install record: one row per workspace per
 *    module, carrying ONLY state (installed/active/inactive) + per-workspace settings.
 *    It uses tenant_id + the BelongsToTenant global scope like every other business
 *    table, so a workspace can never read or flip another workspace's installs.
 *
 * Nothing here is POS-specific: the next module ships as a catalogue row + manifest,
 * never as a new schema.
 *
 * Money: `modules.price_monthly` is WHOLE RUPIAH — the same unit as `plans.price_monthly`
 * (SaaS billing convention, rendered with Money::formatRupiah), never minor units.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('modules', function (Blueprint $table) {
            $table->id();
            $table->string('key', 64)->unique();          // stable machine key: pos, payroll, ...
            $table->string('name', 120);
            $table->string('slug', 80)->unique();
            $table->text('description')->nullable();
            $table->string('icon', 40)->default('cube');  // x-nav-icon name
            $table->string('version', 20)->default('1.0.0');
            $table->string('category', 40)->default('operations');

            // Catalogue availability vs. workspace state: this flag is the platform's
            // "is this module offered at all?" switch; tenant_modules.status is the
            // workspace's own install/activation state.
            $table->boolean('is_active')->default(true);

            // Core modules (dashboard, sales) cannot be uninstalled; they may still be
            // listed so the Modules page tells the whole story.
            $table->boolean('is_core')->default(false);

            // Pricing is stored whole-rupiah and is display/billing metadata only: the
            // module catalogue never moves money. Wiring paid modules to checkout is a
            // later phase; a paid module is simply not installable until then.
            $table->boolean('is_paid')->default(false);
            $table->unsignedInteger('price_monthly')->default(0);
            $table->unsignedInteger('price_yearly')->default(0);
            $table->string('currency', 3)->default('IDR');

            // Minimum plan slug that may install the module (plan entitlement), nullable =
            // available on every plan.
            $table->string('min_plan', 40)->nullable();

            // Manifest hints used to render navigation without hard-coding module links
            // in Blade: the module's entry route name, the business action that guards it
            // and the sidebar group it belongs to.
            $table->string('route', 80)->nullable();
            $table->string('permission', 80)->nullable();
            $table->string('sidebar_group', 60)->nullable();

            // Free-form per-module definition data (declared dependencies, limits, ...).
            $table->json('entitlements')->nullable();

            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['is_active', 'sort_order']);
        });

        Schema::create('tenant_modules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('module_id')->constrained()->cascadeOnDelete();

            // installed = added to the workspace but switched off; active = usable now;
            // inactive = switched off after a previous activation.
            $table->string('status', 20)->default('installed');

            $table->timestamp('installed_at')->nullable();
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('deactivated_at')->nullable();

            // Per-workspace module configuration (e.g. POS default warehouse/terminal).
            $table->json('settings')->nullable();

            $table->timestamps();

            // One install row per workspace per module; the unique index is the
            // concurrency guard so a double-clicked "Install" cannot create two rows.
            $table->unique(['tenant_id', 'module_id']);
            $table->index(['tenant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_modules');
        Schema::dropIfExists('modules');
    }
};
