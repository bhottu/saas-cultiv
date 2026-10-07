<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        DB::table('modules')->insertOrIgnore([
            'key' => 'ai_agent',
            'name' => 'AI Assistant Telegram',
            'slug' => 'ai-agent',
            'description' => 'Manage your workspace through Telegram with help from AI.',
            'icon' => 'sparkles',
            'version' => '1.0.0',
            'category' => 'operations',
            'is_active' => true,
            'is_core' => false,
            'is_paid' => false,
            'price_monthly' => 0,
            'price_yearly' => 0,
            'currency' => 'IDR',
            'min_plan' => null,
            'route' => 'ai.channel.edit',
            'permission' => null,
            'sidebar_group' => null,
            'entitlements' => null,
            'sort_order' => 20,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        // Keep catalog data and any workspace install records intact on rollback.
    }
};
