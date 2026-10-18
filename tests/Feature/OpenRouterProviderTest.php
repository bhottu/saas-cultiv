<?php

namespace Tests\Feature;

use App\Models\AiSetting;
use App\Models\User;
use App\Services\Ai\OpenRouterModelCatalog;
use Database\Seeders\ModuleSeeder;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * OpenRouter is a first-class Cultiv AI provider. These tests cover the provider itself,
 * the encrypted credential, the dynamic (never hard-coded) model catalog with its
 * free/tool-calling priority, the admin UI wiring and the safe failure paths.
 *
 * No test ever performs a real network call — every OpenRouter interaction is Http::fake().
 */
class OpenRouterProviderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ModuleSeeder::class);
        $this->seed(PlanSeeder::class);
    }

    private function admin(string $email = 'openrouter-admin@test.dev'): User
    {
        $admin = User::create([
            'name' => 'OpenRouter Admin',
            'email' => $email,
            'password' => Hash::make('password'),
            'email_verified_at' => now(),
        ]);
        $admin->forceFill(['is_platform_admin' => true])->save();

        return $admin;
    }

    /** A representative slice of the OpenRouter /models payload. */
    private function modelsPayload(): array
    {
        return [
            'data' => [
                [
                    'id' => 'vendor/paid-chat',
                    'name' => 'DDD Paid Chat',
                    'context_length' => 8192,
                    'pricing' => ['prompt' => '0.001', 'completion' => '0.002'],
                    'supported_parameters' => ['temperature'],
                ],
                [
                    'id' => 'vendor/free-tool:free',
                    'name' => 'AAA Free Tool',
                    'context_length' => 16384,
                    'pricing' => ['prompt' => '0', 'completion' => '0'],
                    'supported_parameters' => ['tools', 'tool_choice'],
                ],
                [
                    'id' => 'vendor/paid-tool',
                    'name' => 'CCC Paid Tool',
                    'context_length' => 32768,
                    'pricing' => ['prompt' => '0.005', 'completion' => '0.01'],
                    'supported_parameters' => ['tools'],
                ],
                [
                    'id' => 'vendor/free-chat:free',
                    'name' => 'BBB Free Chat',
                    'context_length' => 8192,
                    'pricing' => ['prompt' => '0', 'completion' => '0'],
                    'supported_parameters' => ['temperature'],
                ],
                [
                    // No `:free` suffix, but pricing is genuinely zero for both sides and it
                    // supports tools — must be treated as free + tool calling.
                    'id' => 'vendor/zero-price-tool',
                    'name' => 'ZZZ Zero Tool',
                    'context_length' => 8192,
                    'pricing' => ['prompt' => '0.0', 'completion' => '0.0'],
                    'supported_parameters' => ['tools'],
                ],
            ],
        ];
    }

    public function test_openrouter_is_available_as_a_provider_on_the_ai_page(): void
    {
        $html = $this->actingAs($this->admin())->get('/admin/ai')->assertOk()->getContent();

        $this->assertStringContainsString('value="openrouter"', $html);
        $this->assertStringContainsString('>OpenRouter</option>', $html);
        $this->assertStringNotContainsString('>Openrouter</option>', $html);
    }

    public function test_openrouter_api_key_is_encrypted_and_never_rendered(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->put('/admin/ai', [
            'provider' => 'openrouter',
            'model' => 'vendor/free-tool:free',
            'fallback_provider' => '',
            'fallback_model' => '',
            'ollama_base_url' => 'http://127.0.0.1:11434',
            'openrouter_api_key' => 'or-secret-key-123',
        ])->assertRedirect('/admin/ai');

        $stored = DB::table('ai_settings')->where('id', 1)->value('openrouter_api_key');
        $this->assertNotSame('or-secret-key-123', $stored);
        $this->assertSame('or-secret-key-123', AiSetting::current()->openrouter_api_key);
        $this->actingAs($admin)->get('/admin/ai')->assertDontSee('or-secret-key-123');
    }

    public function test_existing_providers_are_preserved_alongside_openrouter(): void
    {
        $this->assertSame(['openai', 'gemini', 'ollama', 'openrouter'], AiSetting::PROVIDERS);
    }

    public function test_credential_lookup_is_provider_scoped(): void
    {
        $settings = AiSetting::current()->forceFill([
            'openai_api_key' => 'openai-key',
            'gemini_api_key' => 'gemini-key',
            'openrouter_api_key' => 'openrouter-key',
        ]);

        $this->assertSame('openrouter-key', $settings->apiKeyFor('openrouter'));
        $this->assertSame('openai-key', $settings->apiKeyFor('openai'));
        $this->assertSame('gemini-key', $settings->apiKeyFor('gemini'));
    }

    public function test_openrouter_models_are_fetched_dynamically_and_free_models_lead(): void
    {
        Http::fake(['openrouter.ai/api/v1/models' => Http::response($this->modelsPayload())]);

        AiSetting::current()->forceFill(['provider' => 'openrouter', 'model' => 'vendor/free-tool:free'])->save();

        $html = $this->actingAs($this->admin())->get('/admin/ai')->assertOk()->getContent();

        Http::assertSent(fn ($request) => str_contains($request->url(), '/api/v1/models'));
        $this->assertStringContainsString('value="vendor/free-tool:free"', $html);
        $this->assertStringContainsString('value="vendor/paid-chat"', $html);
        $freePos = strpos($html, 'value="vendor/free-tool:free"');
        $paidPos = strpos($html, 'value="vendor/paid-chat"');
        $this->assertLessThan($paidPos, $freePos, 'The free tool-calling model must be listed before a paid model.');
    }

    public function test_openrouter_model_catalog_sorts_by_free_then_tool_calling_priority(): void
    {
        Http::fake(['openrouter.ai/api/v1/models' => Http::response($this->modelsPayload())]);

        $models = (new OpenRouterModelCatalog)->models();

        $this->assertSame([
            'vendor/free-tool:free',
            'vendor/zero-price-tool',
            'vendor/free-chat:free',
            'vendor/paid-tool',
            'vendor/paid-chat',
        ], array_column($models, 'id'));

        $byId = collect($models)->keyBy('id');
        $this->assertTrue($byId['vendor/free-tool:free']['free']);
        $this->assertTrue($byId['vendor/free-tool:free']['tool_calling']);
        $this->assertTrue($byId['vendor/zero-price-tool']['free'], 'Zero pricing must count as free.');
        $this->assertFalse($byId['vendor/free-chat:free']['tool_calling']);
    }

    public function test_openrouter_model_catalog_is_cached_and_refresh_forces_a_new_fetch(): void
    {
        Http::fake(['openrouter.ai/api/v1/models' => Http::response($this->modelsPayload())]);

        $catalog = new OpenRouterModelCatalog;
        $catalog->models();
        $catalog->models();
        Http::assertSentCount(1);

        $catalog->models(refresh: true);
        Http::assertSentCount(2);
    }

    public function test_openrouter_model_dropdown_only_renders_when_openrouter_is_selected(): void
    {
        Http::fake(['openrouter.ai/*' => Http::response($this->modelsPayload())]);
        AiSetting::current()->forceFill(['provider' => 'gemini', 'model' => 'gemini-2.5-flash'])->save();

        $html = $this->actingAs($this->admin('lazy-admin@test.dev'))->get('/admin/ai')->assertOk()->getContent();
        Http::assertNothingSent();
        $this->assertStringContainsString('data-or-select="primary" class="hidden"', $html);
        $this->assertStringContainsString('data-model-text="primary" class=""', $html);

        AiSetting::current()->forceFill(['provider' => 'openrouter', 'model' => 'vendor/free-tool:free'])->save();
        $html = $this->actingAs($this->admin('openrouter-admin2@test.dev'))->get('/admin/ai')->assertOk()->getContent();
        $this->assertStringContainsString('data-model-text="primary" class="hidden"', $html);
        $this->assertStringContainsString('data-or-select="primary" class=""', $html);
    }

    public function test_openrouter_model_ids_with_slashes_are_saved_correctly(): void
    {
        $this->actingAs($this->admin())->put('/admin/ai', [
            'provider' => 'openrouter',
            'model' => 'google/gemini-2.5-flash:free',
            'fallback_provider' => '',
            'fallback_model' => '',
            'ollama_base_url' => 'http://127.0.0.1:11434',
            'openrouter_api_key' => 'or-key',
        ])->assertRedirect('/admin/ai');

        $this->assertDatabaseHas('ai_settings', [
            'id' => 1,
            'provider' => 'openrouter',
            'model' => 'google/gemini-2.5-flash:free',
        ]);
    }

    public function test_openrouter_can_be_used_as_the_fallback_provider(): void
    {
        $this->actingAs($this->admin())->put('/admin/ai', [
            'provider' => 'gemini',
            'model' => 'gemini-2.5-flash',
            'fallback_provider' => 'openrouter',
            'fallback_model' => 'vendor/free-tool:free',
            'ollama_base_url' => 'http://127.0.0.1:11434',
            'openrouter_api_key' => 'or-fallback-key',
        ])->assertRedirect('/admin/ai');

        $this->assertDatabaseHas('ai_settings', [
            'id' => 1,
            'fallback_provider' => 'openrouter',
            'fallback_model' => 'vendor/free-tool:free',
        ]);
    }

    public function test_openrouter_requires_an_api_key_when_selected(): void
    {
        $this->from('/admin/ai')->actingAs($this->admin())->put('/admin/ai', [
            'provider' => 'openrouter',
            'model' => 'vendor/free-tool:free',
            'fallback_provider' => '',
            'fallback_model' => '',
            'ollama_base_url' => 'http://127.0.0.1:11434',
            'openrouter_api_key' => '',
        ])->assertRedirect('/admin/ai')->assertSessionHasErrors('openrouter_api_key');

        $this->from('/admin/ai')->actingAs($this->admin('gemini-admin@test.dev'))->put('/admin/ai', [
            'provider' => 'gemini',
            'model' => 'gemini-2.5-flash',
            'fallback_provider' => '',
            'fallback_model' => '',
            'ollama_base_url' => 'http://127.0.0.1:11434',
            'openrouter_api_key' => '',
        ])->assertRedirect('/admin/ai')->assertSessionHasNoErrors();
    }
    public function test_openrouter_provider_can_be_tested_successfully(): void
    {
        AiSetting::current()->forceFill([
            'provider' => 'openrouter',
            'model' => 'vendor/free-tool:free',
            'openrouter_api_key' => 'or-test-secret',
        ])->save();

        Http::fake([
            'openrouter.ai/api/v1/chat/completions' => Http::response([
                'choices' => [['message' => ['content' => 'OK']]],
            ]),
        ]);

        $this->actingAs($this->admin())->post('/admin/ai/test')
            ->assertRedirect()
            ->assertSessionHas('status.type', 'success');

        Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer or-test-secret'));
    }

    public function test_openrouter_invalid_api_key_fails_safely(): void
    {
        AiSetting::current()->forceFill([
            'provider' => 'openrouter',
            'model' => 'vendor/free-tool:free',
            'openrouter_api_key' => 'or-bad-key',
        ])->save();

        Http::fake([
            'openrouter.ai/api/v1/chat/completions' => Http::response(['error' => ['message' => 'Invalid API key']], 401),
        ]);

        $this->actingAs($this->admin())->post('/admin/ai/test')
            ->assertRedirect()
            ->assertSessionHas('status.type', 'error');

        $message = session('status.message');
        $this->assertIsString($message);
        $this->assertStringContainsString('OpenRouter', $message);
        $this->assertStringNotContainsString('or-bad-key', $message);
        $this->assertStringNotContainsString('Bearer', $message);
    }

    public function test_openrouter_invalid_model_fails_safely(): void
    {
        AiSetting::current()->forceFill([
            'provider' => 'openrouter',
            'model' => 'vendor/does-not-exist',
            'openrouter_api_key' => 'or-key',
        ])->save();

        Http::fake([
            'openrouter.ai/api/v1/chat/completions' => Http::response(['error' => ['message' => 'Model not found']], 404),
        ]);

        $this->actingAs($this->admin())->post('/admin/ai/test')
            ->assertRedirect()
            ->assertSessionHas('status.type', 'error');

        $message = session('status.message');
        $this->assertIsString($message);
        $this->assertStringNotContainsString('or-key', $message);
    }

    public function test_openrouter_model_list_failure_shows_a_safe_message(): void
    {
        Http::fake(['openrouter.ai/api/v1/models' => Http::response(['message' => 'boom'], 500)]);
        AiSetting::current()->forceFill(['provider' => 'openrouter', 'model' => 'x/y'])->save();

        $response = $this->actingAs($this->admin())->get('/admin/ai')->assertOk();
        // A 5xx from OpenRouter surfaces the specific (safe) "temporarily unavailable"
        // sentence, never the raw provider body ("boom").
        $response->assertSee(__('OpenRouter is temporarily unavailable. Try again shortly.'));
        $response->assertDontSee('boom', false);
    }

    public function test_openrouter_models_json_endpoint_returns_ordered_safe_fields(): void
    {
        AiSetting::current()->forceFill([
            'provider' => 'openrouter',
            'model' => 'vendor/free-tool:free',
            'openrouter_api_key' => 'or-json-secret',
        ])->save();
        Http::fake(['openrouter.ai/api/v1/models' => Http::response($this->modelsPayload())]);

        $response = $this->actingAs($this->admin())
            ->getJson(route('admin.ai.openrouter.models'))
            ->assertOk();

        $this->assertNull($response->json('error'));
        $this->assertSame([
            'vendor/free-tool:free',
            'vendor/zero-price-tool',
            'vendor/free-chat:free',
            'vendor/paid-tool',
            'vendor/paid-chat',
        ], array_column($response->json('models'), 'id'));

        $first = $response->json('models.0');
        $this->assertTrue($first['is_free']);
        $this->assertTrue($first['supports_tools']);
        // Non-free models remain present as a fallback.
        $this->assertContains('vendor/paid-chat', array_column($response->json('models'), 'id'));
        // The credential must never appear in the JSON payload.
        $this->assertStringNotContainsString('or-json-secret', $response->getContent());
        $this->assertStringNotContainsString('Authorization', $response->getContent());
    }

    public function test_openrouter_models_json_endpoint_reports_provider_errors_safely(): void
    {
        Http::fake(['openrouter.ai/api/v1/models' => Http::response(['message' => 'boom'], 500)]);

        $response = $this->actingAs($this->admin())
            ->getJson(route('admin.ai.openrouter.models'))
            ->assertOk();

        $this->assertSame([], $response->json('models'));
        $this->assertSame(__('OpenRouter is temporarily unavailable. Try again shortly.'), $response->json('error'));
        $this->assertStringNotContainsString('boom', $response->getContent());
    }

    public function test_openrouter_models_json_endpoint_maps_an_invalid_key_to_a_safe_message(): void
    {
        Http::fake(['openrouter.ai/api/v1/models' => Http::response(['message' => 'nope'], 401)]);

        $response = $this->actingAs($this->admin())
            ->getJson(route('admin.ai.openrouter.models'))
            ->assertOk();

        $this->assertSame([], $response->json('models'));
        $this->assertSame(
            __('The OpenRouter API key is missing or invalid, or API access is denied.'),
            $response->json('error')
        );
        $this->assertStringNotContainsString('Bearer', $response->getContent());
    }

    public function test_openrouter_models_json_endpoint_handles_an_empty_catalog(): void
    {
        Http::fake(['openrouter.ai/api/v1/models' => Http::response(['data' => []])]);

        $response = $this->actingAs($this->admin())
            ->getJson(route('admin.ai.openrouter.models'))
            ->assertOk();

        $this->assertSame([], $response->json('models'));
        $this->assertNull($response->json('error'));
    }

    public function test_openrouter_models_json_endpoint_refresh_bypasses_the_cache(): void
    {
        Http::fake(['openrouter.ai/api/v1/models' => Http::response($this->modelsPayload())]);
        $admin = $this->admin();

        $this->actingAs($admin)->getJson(route('admin.ai.openrouter.models'))->assertOk();
        $this->actingAs($admin)->getJson(route('admin.ai.openrouter.models'))->assertOk();
        Http::assertSentCount(1); // served from the cache

        $this->actingAs($admin)
            ->getJson(route('admin.ai.openrouter.models', ['refresh' => 1]))
            ->assertOk();
        Http::assertSentCount(2); // refresh forced a re-fetch
    }
}



