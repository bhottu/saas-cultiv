<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('modules')->where('key', 'ai_agent')->update([
            'name' => 'AI Assistant Telegram',
            'description' => 'Manage your workspace through Telegram with help from AI.',
            'icon' => 'sparkles',
            'route' => 'ai.channel.edit',
        ]);
    }

    public function down(): void
    {
        DB::table('modules')->where('key', 'ai_agent')->update([
            'name' => 'Cultiv AI Agent',
            'description' => 'An AI assistant for authorized business questions and confirmed actions in your workspace.',
            'icon' => 'cube',
            'route' => null,
        ]);
    }
};
