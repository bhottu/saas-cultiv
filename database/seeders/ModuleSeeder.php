<?php

namespace Database\Seeders;

use App\Models\Module;
use Illuminate\Database\Seeder;

class ModuleSeeder extends Seeder
{
    public function run(): void
    {
        $registry = config('modules.registry', []);

        foreach ($registry as $definition) {
            Module::updateOrCreate(
                ['key' => $definition['key']],
                $definition + ['is_active' => true]
            );
        }
    }
}
