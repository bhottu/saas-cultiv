<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Server-side "don't show this again" state for user-facing notices.
 *
 * The workspace hub keeps telling an account that it lost access to a workspace. That
 * message is useful once, but a plain `<details>`/Alpine toggle would forget it on the
 * next request, so the notice came back on every page load. One row per (user, notice)
 * key stores the decision on the server, which is what makes it survive a refresh, a
 * logout and a new sign-in.
 *
 * The key is chosen by the caller and is per subject — e.g.
 * "workspace_access_revoked:12" — so dismissing one problem never silences an unrelated
 * or future one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dismissed_notifications', function (Blueprint $table) {
            $table->id();

            // Cascade keeps the table self-cleaning: an account that no longer exists
            // cannot leave orphaned dismissals behind.
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('key', 100);
            $table->timestamp('dismissed_at')->nullable();

            $table->timestamps();

            // Dismissing the same notice twice must stay a no-op, not a second row.
            $table->unique(['user_id', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dismissed_notifications');
    }
};
