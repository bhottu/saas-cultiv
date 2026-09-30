<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-workspace custom branding (logo, name, tagline).
 *
 * Branding belongs to the WORKSPACE, not the account: the same person belongs to
 * several workspaces and each one presents itself with its own name (a shop, a
 * client, a branch). The fields therefore live on `tenants`, next to the other
 * workspace-owned presentation data.
 *
 * All three are nullable on purpose. NULL means "not customised", and the shell then
 * falls back to the Cultiv defaults from config/app.php — an account with no
 * branding keeps the product identity instead of showing blanks.
 *
 * The logo is a storage path on the configured uploads disk, not a public URL: the
 * file is served through an authenticated, workspace-scoped route, so a brand mark
 * cannot be fetched by guessing a filename.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            // Caps mirror the form: 30 keeps the sidebar/header lockup on one line,
            // 60 keeps the tagline from wrapping into the menu below it.
            $table->string('brand_name', 30)->nullable()->after('name');
            $table->string('brand_tagline', 60)->nullable()->after('brand_name');
            $table->string('brand_logo_path')->nullable()->after('brand_tagline');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn(['brand_name', 'brand_tagline', 'brand_logo_path']);
        });
    }
};
