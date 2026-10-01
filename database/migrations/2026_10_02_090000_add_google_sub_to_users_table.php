<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Links a Cultiv One account to the Google account that signs in to it.
 *
 * `google_sub` is Google's OpenID Connect `sub` claim — a stable, per-client
 * identifier for one Google account. It is the PRIMARY link between the two systems,
 * and email is only ever a matching hint: an email can change, and it is not unique
 * across providers.
 *
 * Nullable and uniquely indexed:
 *  - nullable, so every existing manual-registration account keeps working untouched
 *    and no backfill is required;
 *  - unique, so two Cultiv users can never claim the same Google account. PostgreSQL
 *    and MySQL both allow many NULLs under a unique index, which is exactly what we
 *    want for the (many) users who have not linked Google.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('google_sub')->nullable()->unique()->after('email');
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('users', 'google_sub')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropUnique(['google_sub']);
                $table->dropColumn('google_sub');
            });
        }
    }
};