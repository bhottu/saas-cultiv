<?php

use App\Models\SeoSetting;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Platform-wide SEO overrides.
 *
 * config/seo.php remains the source of defaults and is never edited by this table: a
 * fresh install, a test run and a rollback to "no admin overrides" all keep working from
 * config alone. This table only records what an administrator has explicitly set, and
 * every field is nullable so "not configured" stays distinguishable from "set to empty".
 *
 * Deliberately a single-row, typed table rather than a key/value bag: the SEO surface is
 * small and known, so the columns can be typed, indexed by nothing and validated by
 * Laravel, and there is no decode step between the admin form and the rendered <head>.
 *
 * Asset columns hold paths RELATIVE TO the configured uploads disk, exactly like
 * tenants.brand_logo_path, so switching disks in one place moves the assets with it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seo_settings', function (Blueprint $table) {
            $table->id();

            // --- General ---------------------------------------------------------
            $table->string('site_name')->nullable();
            $table->string('default_title')->nullable();
            $table->string('default_description', 1000)->nullable();
            $table->string('canonical_url')->nullable();

            // Drives <meta name="theme-color"> in the layouts. Blank falls back to
            // config('seo.theme_color').
            $table->string('theme_color', 20)->nullable();

            // Master switch. Indexing stays deny-by-omission per route (Seo::robots()),
            // so this can only turn the whole site OFF — never force a private page in.
            //
            // Defaults to TRUE because that is the behaviour the site had before this
            // table existed. Defaulting it to false would silently de-index a live
            // production site the moment the migration ran, which is exactly the kind of
            // unreviewed change an "SEO settings" screen must never make on its own.
            $table->boolean('allow_indexing')->default(SeoSetting::DEFAULT_ALLOW_INDEXING);

            // --- Assets ----------------------------------------------------------
            $table->string('favicon_path')->nullable();
            $table->string('og_image_path')->nullable();

            // --- Open Graph ------------------------------------------------------
            $table->string('og_title')->nullable();
            $table->string('og_description', 1000)->nullable();
            $table->string('og_type')->nullable();
            $table->string('og_site_name')->nullable();

            // --- Twitter / X -----------------------------------------------------
            $table->string('twitter_card_type')->nullable();
            $table->string('twitter_title')->nullable();
            $table->string('twitter_description', 1000)->nullable();
            $table->string('twitter_image_path')->nullable();
            $table->string('twitter_handle')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seo_settings');
    }
};