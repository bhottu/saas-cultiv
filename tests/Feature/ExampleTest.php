<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_application_returns_a_successful_response(): void
    {
        $this->seed(\Database\Seeders\PlanSeeder::class);

        $this->get('/')
            ->assertOk()
            ->assertSee('Cultiv One')
            ->assertSee('The smarter way to manage your business')
            ->assertSee('<title>Cultiv One - The smarter way to manage your business</title>', false)
            ->assertSee('favicon.svg', false);
    }
}

