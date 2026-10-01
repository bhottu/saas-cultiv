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
            // The landing page states its own SEO title, so this asserts the title and
            // description are present rather than pinning the old generated string.
            ->assertSee('<title>', false)
            ->assertSee('name="description"', false)
            ->assertSee('favicon.svg', false);
    }
}

