<?php

namespace Database\Seeders;

use App\Models\User;
// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call(PlanSeeder::class);

        // Platform admin for the /admin panel.
        User::updateOrCreate(
            ['email' => 'admin@example.com'],
            ['name' => 'Platform Admin', 'password' => bcrypt(env('SEED_ADMIN_PASSWORD', 'ChangeMe!2026')), 'is_platform_admin' => true, 'email_verified_at' => now()]
        );

        User::factory()->create([
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);
    }
}
