<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-user interface language.
 *
 * Language belongs to the ACCOUNT, not the workspace: the same person belongs to
 * several workspaces and each workspace may be staffed by people who read a
 * different language, so a workspace-level setting would force one language on
 * everybody. Branding stays per workspace (`tenants.brand_*`); this is the mirror
 * image of that decision and deliberately lives on the other side of the system.
 *
 * Nullable with no backfill: NULL means "never chose", which resolves to the
 * application default (config/app.php locale, Indonesian). Existing rows therefore
 * keep working untouched, and no deploy-time data migration is needed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Short code, not a display name: this is what App::setLocale() consumes.
            $table->string('locale', 5)->nullable()->after('email');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('locale');
        });
    }
};