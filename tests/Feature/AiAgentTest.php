<?php

namespace Tests\Feature;

use App\Exceptions\SubscriptionLimitException;
use App\Models\AiChannelLink;
use App\Models\AiPendingAction;
use App\Models\AiSetting;
use App\Models\Module;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Sale;
use App\Models\StockBalance;
use App\Models\Tenant;
use App\Models\TenantModule;
use App\Models\UsageRecord;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Ai\AiAgent;
use App\Services\Ai\AiPendingSaleService;
use App\Services\Ai\AiProviderException;
use App\Services\Ai\AiProviderManager;
use App\Services\Ai\AiProviderResponse;
use App\Services\Ai\BusinessAiTools;
use App\Services\Ai\GeminiProvider;
use App\Services\Ai\TelegramClient;
use App\Services\Ai\TelegramHtmlFormatter;
use App\Services\Ai\TelegramLinkService;
use App\Services\ModuleManager;
use App\Services\TenantContext;
use App\Services\UsageService;
use Database\Seeders\ModuleSeeder;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\TestCase;

class AiAgentTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Tenant $tenant;

    private Product $product;

    private Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ModuleSeeder::class);
        $this->seed(PlanSeeder::class);

        $this->owner = User::create([
            'name' => 'AI Owner',
            'email' => 'ai-owner@test.dev',
            'password' => Hash::make('password'),
            'email_verified_at' => now(),
        ]);
        $this->tenant = Tenant::create([
            'name' => 'AI Workspace',
            'slug' => 'ai-workspace',
            'owner_id' => $this->owner->id,
            'status' => 'active',
        ]);
        $this->tenant->users()->attach($this->owner->id, [
            'role' => 'Owner', 'status' => 'active', 'joined_at' => now(),
        ]);
        $freePlan = Plan::query()->where('is_free_tier', true)->firstOrFail();
        $freeEntitlements = $freePlan->entitlements;
        $freeEntitlements['cultiv_ai'] = true;
        $freePlan->forceFill(['entitlements' => $freeEntitlements])->save();
        $this->warehouse = Warehouse::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Main Warehouse',
            'code' => 'MAIN',
            'is_active' => true,
        ]);
        $this->product = Product::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Arabica Coffee',
            'sku' => 'COF-001',
            'barcode' => '899001',
            'selling_price' => 150000,
            'cost_price' => 100000,
            'track_inventory' => true,
            'is_active' => true,
        ]);
        StockBalance::create([
            'tenant_id' => $this->tenant->id,
            'warehouse_id' => $this->warehouse->id,
            'product_id' => $this->product->id,
            'quantity' => 8,
            'incoming' => 8,
            'outgoing' => 0,
        ]);
        $this->activateAiAgent();
    }

    private function activateAiAgent(): void
    {
        $module = Module::query()->where('key', 'ai_agent')->firstOrFail();
        TenantModule::create([
            'tenant_id' => $this->tenant->id,
            'module_id' => $module->id,
            'status' => TenantModule::STATUS_ACTIVE,
            'installed_at' => now(),
            'activated_at' => now(),
        ]);
    }

    private function contextAsOwner(): void
    {
        app(TenantContext::class)->set($this->tenant, $this->owner);
    }

    public function test_admin_ai_page_creates_the_default_platform_configuration(): void
    {
        $this->assertTrue(Schema::hasTable('ai_settings'));
        AiSetting::query()->delete();

        $admin = User::create([
            'name' => 'Platform Admin',
            'email' => 'ai-default-admin@test.dev',
            'password' => Hash::make('password'),
            'email_verified_at' => now(),
        ]);
        $admin->forceFill(['is_platform_admin' => true])->save();

        $this->actingAs($admin)->get('/admin/ai')
            ->assertOk()
            ->assertSee('gpt-4o-mini')
            ->assertSee('http://127.0.0.1:11434');

        $this->assertDatabaseHas('ai_settings', [
            'id' => 1,
            'provider' => 'openai',
            'model' => 'gpt-4o-mini',
        ]);
        $this->assertSame(1, AiSetting::query()->count());
    }

    public function test_provider_credentials_are_encrypted_and_connection_test_uses_saved_configuration(): void
    {
        $admin = User::create([
            'name' => 'Platform Admin',
            'email' => 'ai-admin@test.dev',
            'password' => Hash::make('password'),
            'email_verified_at' => now(),
        ]);
        $admin->forceFill(['is_platform_admin' => true])->save();

        $response = $this->actingAs($admin)->put('/admin/ai', [
            'provider' => 'openai',
            'model' => 'gpt-4o-mini',
            'fallback_provider' => '',
            'fallback_model' => '',
            'ollama_base_url' => 'http://127.0.0.1:11434',
            'openai_api_key' => 'test-provider-secret',
            'gemini_api_key' => '',
            'telegram_bot_token' => '',
        ]);
        $response->assertRedirect('/admin/ai');

        $stored = DB::table('ai_settings')->where('id', 1)->value('openai_api_key');
        $this->assertNotSame('test-provider-secret', $stored);
        $this->assertSame('test-provider-secret', AiSetting::current()->openai_api_key);
        $this->actingAs($admin)->get('/admin/ai')->assertDontSee('test-provider-secret');

        $this->actingAs($admin)->put('/admin/ai', [
            'provider' => 'openai',
            'model' => 'gpt-4o-mini',
            'fallback_provider' => '',
            'fallback_model' => '',
            'ollama_base_url' => 'http://127.0.0.1:11434',
            'openai_api_key' => '',
            'gemini_api_key' => '',
            'telegram_bot_token' => '',
        ])->assertRedirect('/admin/ai');
        $this->assertSame('test-provider-secret', AiSetting::current()->openai_api_key);

        Http::fake([
            'api.openai.com/v1/chat/completions' => Http::response([
                'choices' => [['message' => ['content' => 'OK']]],
            ]),
        ]);
        $this->actingAs($admin)->post('/admin/ai/test')
            ->assertRedirect()
            ->assertSessionHas('status.type', 'success');
        Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer test-provider-secret'));
    }

    public function test_non_platform_admin_cannot_access_ai_configuration(): void
    {
        $this->actingAs($this->owner)->get('/admin/ai')->assertForbidden();
    }

    public function test_ai_module_installation_and_use_follow_the_workspace_plan_entitlement(): void
    {
        $module = Module::query()->where('key', 'ai_agent')->firstOrFail();
        TenantModule::query()->where('tenant_id', $this->tenant->id)
            ->where('module_id', $module->id)->delete();
        $free = Plan::query()->where('is_free_tier', true)->firstOrFail();
        $entitlements = $free->entitlements;
        $entitlements['cultiv_ai'] = false;
        $free->forceFill(['entitlements' => $entitlements])->save();
        $workspace = $this->actingAs($this->owner)->withSession(['tenant_id' => $this->tenant->id]);

        $workspace->get('/modules')->assertOk()
            ->assertSee('AI Assistant Telegram')
            ->assertSee(__('Module details'))
            ->assertSee(__('Manage your workspace through Telegram with help from AI.'))
            ->assertSee('No active plans currently include this feature.');
        $workspace->post(route('modules.install', $module))
            ->assertRedirect()
            ->assertSessionHas('status.type', 'error');
        $this->assertDatabaseMissing('tenant_modules', [
            'tenant_id' => $this->tenant->id,
            'module_id' => $module->id,
        ]);
        $workspace->get('/settings/ai-channel')->assertNotFound();

        $entitlements['cultiv_ai'] = true;
        $free->forceFill(['entitlements' => $entitlements])->save();
        $workspace->post(route('modules.install', $module))->assertRedirect();
        $workspace->post(route('modules.activate', $module))->assertRedirect();
        $workspace->get('/settings/ai-channel')->assertOk();
        $workspace->get('/modules')->assertOk()
            ->assertSee('AI Assistant Telegram')
            ->assertSee(route('ai.channel.edit'), false);

        $entitlements['cultiv_ai'] = false;
        $free->forceFill(['entitlements' => $entitlements])->save();
        $workspace->get('/settings/ai-channel')->assertNotFound();
        $this->assertFalse(app(ModuleManager::class)->active('ai_agent', $this->tenant));
    }

    public function test_ai_module_catalog_status_controls_visibility_installation_and_access(): void
    {
        $module = Module::query()->where('key', 'ai_agent')->firstOrFail();
        TenantModule::query()->where('tenant_id', $this->tenant->id)
            ->where('module_id', $module->id)->delete();
        $admin = User::create([
            'name' => 'Platform Admin',
            'email' => 'ai-module-admin@test.dev',
            'password' => Hash::make('password'),
            'email_verified_at' => now(),
        ]);
        $admin->forceFill(['is_platform_admin' => true])->save();
        $workspace = fn () => $this->actingAs($this->owner)->withSession(['tenant_id' => $this->tenant->id]);

        $this->actingAs($admin)->get('/admin/modules')->assertOk()->assertSee('AI Assistant Telegram');
        $workspace()->get('/modules')->assertOk()->assertSee('AI Assistant Telegram');

        $this->actingAs($admin)->put('/admin/modules/ai-agent', ['availability_status' => 'hidden'])
            ->assertRedirect('/admin/modules');
        $workspace()->get('/modules')->assertOk()->assertDontSee('AI Assistant Telegram');
        $workspace()->get('/modules/ai-agent')->assertNotFound();
        $workspace()->post('/modules/ai-agent/install')->assertRedirect()
            ->assertSessionHas('status.type', 'error');
        $this->assertDatabaseMissing('tenant_modules', [
            'tenant_id' => $this->tenant->id,
            'module_id' => $module->id,
        ]);

        $this->actingAs($admin)->put('/admin/modules/ai-agent', ['availability_status' => 'maintenance'])
            ->assertRedirect('/admin/modules');
        $workspace()->get('/modules')->assertOk()
            ->assertSee('AI Assistant Telegram')
            ->assertSee('Under maintenance');
        $workspace()->post('/modules/ai-agent/install')->assertRedirect()
            ->assertSessionHas('status.type', 'error');
        $workspace()->get('/settings/ai-channel')->assertNotFound();

        $this->actingAs($admin)->put('/admin/modules/ai-agent', ['availability_status' => 'active'])
            ->assertRedirect('/admin/modules');
        $workspace()->post('/modules/ai-agent/install')->assertRedirect();
        $workspace()->post('/modules/ai-agent/activate')->assertRedirect();
        $workspace()->get('/settings/ai-channel')->assertOk();
    }

    public function test_gemini_connection_test_sends_a_valid_request_to_the_selected_model(): void
    {
        $admin = User::create([
            'name' => 'Platform Admin',
            'email' => 'ai-gemini-test-admin@test.dev',
            'password' => Hash::make('password'),
            'email_verified_at' => now(),
        ]);
        $admin->forceFill(['is_platform_admin' => true])->save();
        AiSetting::current()->forceFill([
            'provider' => 'gemini',
            'model' => 'gemini-2.5-flash',
            'fallback_provider' => null,
            'fallback_model' => null,
            'gemini_api_key' => 'gemini-test-secret',
        ])->save();
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [['content' => ['parts' => [['text' => 'OK']]]]],
            ]),
        ]);

        $this->actingAs($admin)->post('/admin/ai/test')
            ->assertRedirect()
            ->assertSessionHas('status.type', 'success');

        Http::assertSent(fn ($request) => str_contains(
            $request->url(),
            '/v1beta/models/gemini-2.5-flash:generateContent',
        ) && $request->hasHeader('x-goog-api-key', 'gemini-test-secret')
            && ($request->data()['contents'][0]['parts'][0]['text'] ?? null) === 'Reply with the single word OK.');
    }

    public function test_gemini_retries_transient_server_errors_but_succeeds_when_provider_recovers(): void
    {
        $settings = AiSetting::current();
        $settings->forceFill([
            'provider' => 'gemini',
            'model' => 'gemini-2.5-flash-lite',
            'gemini_api_key' => 'gemini-retry-test-secret',
        ])->save();
        Http::fakeSequence('generativelanguage.googleapis.com/*')
            ->push(['error' => ['status' => 'UNAVAILABLE', 'message' => 'Temporary overload.']], 503)
            ->push(['candidates' => [['content' => ['parts' => [['text' => 'OK']]]]]], 200);

        $response = (new GeminiProvider($settings, 'gemini-2.5-flash-lite'))
            ->complete([['role' => 'user', 'content' => 'Reply with the single word OK.']], []);

        $this->assertSame('OK', $response->text);
        $this->assertCount(2, Http::recorded());
    }

    public function test_gemini_tool_call_checks_stock_and_returns_the_tool_result_for_a_final_answer(): void
    {
        $this->contextAsOwner();
        $link = AiChannelLink::create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $this->owner->id,
            'channel' => 'telegram',
            'external_id' => '91002',
            'linked_at' => now(),
        ]);
        AiSetting::current()->forceFill([
            'provider' => 'gemini',
            'model' => 'gemini-2.5-flash-lite',
            'gemini_api_key' => 'gemini-tool-test-secret',
            'fallback_provider' => null,
            'fallback_model' => null,
            'telegram_bot_token' => '91002:bot-secret',
            'telegram_webhook_secret' => 'webhook-secret',
        ])->save();

        $providerPayloads = [];
        Http::fake(function ($request) use (&$providerPayloads) {
            if (str_contains($request->url(), 'generativelanguage.googleapis.com')) {
                $providerPayloads[] = $request->data();
                if (count($providerPayloads) === 1) {
                    return Http::response([
                        'candidates' => [[
                            'content' => ['parts' => [[
                                'functionCall' => [
                                    'name' => 'get_stock',
                                    'args' => ['query' => 'Arabica Coffee'],
                                ],
                                'thoughtSignature' => 'gemini-thought-signature',
                            ]]],
                        ]],
                    ]);
                }

                return Http::response([
                    'candidates' => [['content' => ['parts' => [['text' => 'Arabica Coffee has 8 units in Main Warehouse.']]]]],
                ]);
            }

            return Http::response(['ok' => true, 'result' => []]);
        });

        $this->postJson('/api/ai/telegram/webhook', [
            'update_id' => 22011,
            'message' => [
                'chat' => ['id' => 91002, 'type' => 'private'],
                'from' => ['id' => 91002, 'is_bot' => false],
                'text' => 'Berapa stok Arabica Coffee?',
            ],
        ], ['X-Telegram-Bot-Api-Secret-Token' => 'webhook-secret'])->assertOk();

        $this->assertCount(2, $providerPayloads);
        $this->assertContains('get_stock', array_column(
            $providerPayloads[0]['tools'][0]['functionDeclarations'],
            'name',
        ));
        $this->assertSame(
            'gemini-thought-signature',
            $providerPayloads[1]['contents'][1]['parts'][0]['thoughtSignature'],
        );
        $functionResponse = $providerPayloads[1]['contents'][2]['parts'][0]['functionResponse'];
        $this->assertSame('get_stock', $functionResponse['name']);
        $this->assertSame('stock_found', $functionResponse['response']['status']);
        $this->assertSame(8, $functionResponse['response']['stock_by_warehouse'][0]['quantity']);
        Http::assertSent(fn ($request) => str_contains($request->url(), 'api.telegram.org')
            && ($request->data()['text'] ?? null) === 'Arabica Coffee has 8 units in Main Warehouse.');
    }

    public function test_gemini_tool_call_without_a_product_returns_a_clarification_and_distinguishes_stock_lookup_results(): void
    {
        $this->contextAsOwner();
        $link = AiChannelLink::create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $this->owner->id,
            'channel' => 'telegram',
            'external_id' => 'telegram-stock-clarification',
            'linked_at' => now(),
        ]);
        $tools = app(BusinessAiTools::class);

        $clarification = json_decode($tools->execute('get_stock', [], $link)->content, true);
        $this->assertSame('product_required', $clarification['status']);
        $this->assertStringContainsString('Which product', $clarification['message']);
        $invalid = json_decode($tools->execute('get_stock', ['query' => str_repeat('x', 101)], $link)->content, true);
        $this->assertSame('invalid_arguments', $invalid['status']);
        $unknown = json_decode($tools->execute('unknown_tool', [], $link)->content, true);
        $this->assertSame('unsupported_tool', $unknown['status']);

        $stock = json_decode($tools->execute('get_stock', ['query' => 'COF-001'], $link)->content, true);
        $this->assertSame('stock_found', $stock['status']);
        $this->assertSame('Arabica Coffee', $stock['product']);
        $this->assertSame(8, $stock['stock_by_warehouse'][0]['quantity']);

        $notFound = json_decode($tools->execute('get_stock', ['query' => 'missing-product'], $link)->content, true);
        $this->assertSame('product_not_found', $notFound['status']);

        Product::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Arabica Dark Roast',
            'sku' => 'COF-002',
            'selling_price' => 160000,
            'track_inventory' => true,
            'is_active' => true,
        ]);
        $ambiguous = json_decode($tools->execute('get_stock', ['query' => 'Arabica'], $link)->content, true);
        $this->assertSame('multiple_products_found', $ambiguous['status']);
        $this->assertCount(2, $ambiguous['products']);
    }

    public function test_ai_agent_handles_empty_provider_responses_and_stops_tool_loops(): void
    {
        $this->contextAsOwner();
        $link = AiChannelLink::create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $this->owner->id,
            'channel' => 'telegram',
            'external_id' => 'telegram-agent-edge-cases',
            'linked_at' => now(),
        ]);
        $emptyProvider = \Mockery::mock(AiProviderManager::class);
        $emptyProvider->shouldReceive('complete')->once()
            ->andReturn(new AiProviderResponse(null));
        $emptyAgent = new AiAgent($emptyProvider, app(BusinessAiTools::class));
        $this->assertSame(
            __('I could not prepare a response. Please try again.'),
            $emptyAgent->respond('Hello', $link)->content,
        );

        $loopProvider = \Mockery::mock(AiProviderManager::class);
        $loopProvider->shouldReceive('complete')->times(4)->andReturn(
            new AiProviderResponse(null, [[
                'id' => 'loop-call',
                'name' => 'search_products',
                'arguments' => ['query' => 'not-found'],
            ]]),
        );
        $loopAgent = new AiAgent($loopProvider, app(BusinessAiTools::class));
        $this->assertSame(
            __('I could not complete that request. Please try a simpler question.'),
            $loopAgent->respond('Find not-found', $link)->content,
        );
    }

    public function test_ai_tool_rejects_a_telegram_link_used_with_another_workspace_context(): void
    {
        $this->contextAsOwner();
        $link = AiChannelLink::create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $this->owner->id,
            'channel' => 'telegram',
            'external_id' => 'telegram-workspace-boundary',
            'linked_at' => now(),
        ]);
        $foreignOwner = User::create([
            'name' => 'Foreign AI Owner',
            'email' => 'foreign-ai-owner@test.dev',
            'password' => Hash::make('password'),
            'email_verified_at' => now(),
        ]);
        $foreignTenant = Tenant::create([
            'name' => 'Foreign AI Workspace',
            'slug' => 'foreign-ai-workspace',
            'owner_id' => $foreignOwner->id,
            'status' => 'active',
        ]);
        $foreignTenant->users()->attach($foreignOwner->id, [
            'role' => 'Owner', 'status' => 'active', 'joined_at' => now(),
        ]);
        app(TenantContext::class)->set($foreignTenant, $foreignOwner);

        try {
            app(BusinessAiTools::class)->execute('get_stock', ['product_id' => $this->product->id], $link);
            $this->fail('Expected a cross-workspace tool call to be rejected.');
        } catch (HttpExceptionInterface $error) {
            $this->assertSame(403, $error->getStatusCode());
        }
    }

    public function test_ai_provider_failure_is_logged_with_safe_diagnostics_and_a_user_safe_message(): void
    {
        AiChannelLink::create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $this->owner->id,
            'channel' => 'telegram',
            'external_id' => '91002',
            'linked_at' => now(),
        ]);
        AiSetting::current()->forceFill([
            'provider' => 'gemini',
            'model' => 'gemini-2.5-flash-lite',
            'gemini_api_key' => 'gemini-provider-test-secret',
            'fallback_provider' => null,
            'fallback_model' => null,
            'telegram_bot_token' => '91002:bot-secret',
            'telegram_webhook_secret' => 'webhook-secret',
        ])->save();
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'error' => [
                    'status' => 'INVALID_ARGUMENT',
                    'message' => 'Rejected gemini-provider-test-secret',
                ],
            ], 400),
            'api.telegram.org/*' => Http::response(['ok' => true, 'result' => []]),
        ]);
        Log::spy();

        $this->postJson('/api/ai/telegram/webhook', [
            'update_id' => 22012,
            'message' => [
                'chat' => ['id' => 91002, 'type' => 'private'],
                'from' => ['id' => 91002, 'is_bot' => false],
                'text' => 'Halo cultiv',
            ],
        ], ['X-Telegram-Bot-Api-Secret-Token' => 'webhook-secret'])->assertOk();
        $telegramTexts = Http::recorded(fn ($request) => str_contains($request->url(), 'api.telegram.org'))
            ->map(fn (array $pair) => $pair[0]->data()['text'] ?? '')
            ->all();
        $this->assertContains(
            __('The AI service is temporarily unavailable. Please try again later.'),
            $telegramTexts,
            json_encode($telegramTexts),
        );
        Log::shouldHaveReceived('warning')->withArgs(function (string $message, array $context): bool {
            return $message === 'ai.assistant.provider_failed'
                && ($context['provider'] ?? null) === 'gemini'
                && ($context['model'] ?? null) === 'gemini-2.5-flash-lite'
                && ($context['provider_status'] ?? null) === 400
                && ($context['provider_api_status'] ?? null) === 'INVALID_ARGUMENT'
                && ! str_contains(json_encode($context), 'gemini-provider-test-secret');
        })->once();
    }

    public function test_ai_provider_timeout_is_reported_safely_to_telegram(): void
    {
        AiChannelLink::create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $this->owner->id,
            'channel' => 'telegram',
            'external_id' => '91002',
            'linked_at' => now(),
        ]);
        AiSetting::current()->forceFill([
            'provider' => 'gemini',
            'model' => 'gemini-2.5-flash-lite',
            'gemini_api_key' => 'gemini-timeout-test-secret',
            'fallback_provider' => null,
            'fallback_model' => null,
            'telegram_bot_token' => '91002:bot-secret',
            'telegram_webhook_secret' => 'webhook-secret',
        ])->save();
        $provider = \Mockery::mock(AiProviderManager::class);
        $provider->shouldReceive('complete')->once()->andThrow(
            new AiProviderException(
                'gemini',
                'gemini-2.5-flash-lite',
                'transport_failure',
                transportType: 'request_timeout',
                elapsedMilliseconds: 35000,
            ),
        );
        $this->app->instance(AiProviderManager::class, $provider);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => []])]);
        Log::spy();

        $this->postJson('/api/ai/telegram/webhook', [
            'update_id' => 22013,
            'message' => [
                'chat' => ['id' => 91002, 'type' => 'private'],
                'from' => ['id' => 91002, 'is_bot' => false],
                'text' => 'Cek stok',
            ],
        ], ['X-Telegram-Bot-Api-Secret-Token' => 'webhook-secret'])->assertOk();

        $telegramTexts = Http::recorded(fn ($request) => str_contains($request->url(), 'api.telegram.org'))
            ->map(fn (array $pair) => $pair[0]->data()['text'] ?? '')
            ->all();
        $this->assertContains(
            __('The AI service is temporarily unavailable. Please try again later.'),
            $telegramTexts,
            json_encode($telegramTexts),
        );
        Log::shouldHaveReceived('warning')->withArgs(function (string $message, array $context): bool {
            return $message === 'ai.assistant.provider_failed'
                && ($context['reason'] ?? null) === 'transport_failure'
                && ($context['duration_ms'] ?? null) >= 0
                && ! str_contains(json_encode($context), 'gemini-timeout-test-secret');
        })->once();
    }

    public function test_gemini_transport_diagnostics_classify_curl_timeout_without_exposing_credentials(): void
    {
        $exception = AiProviderException::transportFailure(
            'gemini',
            'gemini-2.5-flash-lite',
            new ConnectionException(
                'cURL error 28: Operation timed out after 35001 milliseconds with 0 bytes received for https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash-lite:generateContent?key=diagnostic-secret',
            ),
            'diagnostic-secret',
            35010,
            5,
            35,
        );

        $this->assertSame('request_timeout', $exception->transportType);
        $this->assertSame('generativelanguage.googleapis.com', $exception->host);
        $this->assertSame(443, $exception->port);
        $this->assertSame(35010, $exception->elapsedMilliseconds);
        $this->assertStringContainsString('cURL error 28', $exception->diagnosticMessage());
        $this->assertStringNotContainsString('diagnostic-secret', $exception->diagnosticMessage());
        $this->assertStringNotContainsString('?key=', $exception->diagnosticMessage());
    }

    public function test_ai_provider_failure_logs_safe_provider_diagnostics(): void
    {
        $admin = User::create([
            'name' => 'Platform Admin',
            'email' => 'ai-provider-error-admin@test.dev',
            'password' => Hash::make('password'),
            'email_verified_at' => now(),
        ]);
        $admin->forceFill(['is_platform_admin' => true])->save();
        AiSetting::current()->forceFill([
            'provider' => 'gemini',
            'model' => 'gemini-invalid-model',
            'gemini_api_key' => 'provider-secret-for-test',
        ])->save();
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'error' => ['message' => 'Rejected request with provider-secret-for-test'],
            ], 404),
        ]);
        Log::spy();

        $this->actingAs($admin)->post('/admin/ai/test')
            ->assertRedirect()
            ->assertSessionHas('status.type', 'error')
            ->assertSessionHas('status.message', __('Connection test failed for :provider: :reason', [
                'provider' => 'Gemini',
                'reason' => __('Gemini model :model is unavailable for generateContent. Check that the model ID supports generateContent, for example gemini-2.5-flash-lite.', [
                    'model' => 'gemini-invalid-model',
                ]),
            ]).' '.__('Provider error: :status :details', [
                'status' => __('Unknown status'),
                'details' => 'Rejected request with [redacted]',
            ]).' (HTTP 404)');
        $this->assertStringNotContainsString('provider-secret-for-test', session('status.message'));
        Log::shouldHaveReceived('warning')->withArgs(function (string $message, array $context): bool {
            return $message === 'ai.provider.connection_test_failed'
                && ($context['provider'] ?? null) === 'gemini'
                && ($context['model'] ?? null) === 'gemini-invalid-model'
                && ($context['reason'] ?? null) === 'model_or_endpoint_not_found'
                && ($context['http_status'] ?? null) === 404
                && ! str_contains(json_encode($context), 'provider-secret-for-test');
        })->once();
        $this->assertCount(1, Http::recorded());
    }

    public function test_gemini_authentication_errors_are_explained_without_exposing_provider_text(): void
    {
        $admin = User::create([
            'name' => 'Platform Admin',
            'email' => 'ai-gemini-auth-admin@test.dev',
            'password' => Hash::make('password'),
            'email_verified_at' => now(),
        ]);
        $admin->forceFill(['is_platform_admin' => true])->save();
        AiSetting::current()->forceFill([
            'provider' => 'gemini',
            'model' => 'gemini-2.5-flash',
            'gemini_api_key' => 'gemini-auth-secret',
        ])->save();
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'error' => [
                    'status' => 'INVALID_ARGUMENT',
                    'message' => 'API key not valid. Rejected key: gemini-auth-secret',
                ],
            ], 400),
        ]);

        $this->actingAs($admin)->post('/admin/ai/test')
            ->assertRedirect()
            ->assertSessionHas('status.type', 'error')
            ->assertSessionHas('status.message', __('Connection test failed for :provider: :reason', [
                'provider' => 'Gemini',
                'reason' => __('The API key is missing or invalid, or API access is denied.'),
            ]).' '.__('Provider error: :status :details', [
                'status' => 'INVALID_ARGUMENT',
                'details' => 'API key not valid. Rejected key: [redacted]',
            ]).' (HTTP 400)');

        $this->assertStringNotContainsString('gemini-auth-secret', session('status.message'));
    }

    public function test_telegram_send_text_renders_markdown_as_safe_telegram_html(): void
    {
        AiSetting::current()->forceFill(['telegram_bot_token' => '91002:bot-secret'])->save();
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => []])]);
        $link = new AiChannelLink(['channel' => 'telegram', 'external_id' => '91002']);
        $message = <<<'MESSAGE'
**Ringkasan Penjualan**

*Italic text*

1. First numbered item
2. Second numbered item

- First bullet
- Second bullet

Inline `code` example.

```php
if ($stock < 2 && $ready) {
    echo "low";
}
```

[Open report](https://example.com/reports?id=1&kind=sales)

Literal <tag> & symbols; not trusted HTML: <img src=x onerror=alert(1)>
MESSAGE;

        app(TelegramClient::class)->sendText($link, $message);

        $requests = Http::recorded(fn ($request) => str_contains($request->url(), 'sendMessage'));
        $this->assertCount(1, $requests);
        $payload = $requests->first()[0]->data();
        $html = $payload['text'];
        $this->assertSame('HTML', $payload['parse_mode']);
        $this->assertStringContainsString('<b>Ringkasan Penjualan</b>', $html);
        $this->assertStringContainsString('<i>Italic text</i>', $html);
        $this->assertStringContainsString('1. First numbered item', $html);
        $this->assertStringContainsString('2. Second numbered item', $html);
        $this->assertStringContainsString('• First bullet', $html);
        $this->assertStringContainsString('<code>code</code>', $html);
        $this->assertStringContainsString('<pre>if ($stock &lt; 2 &amp;&amp; $ready)', $html);
        $this->assertStringContainsString('<a href="https://example.com/reports?id=1&amp;kind=sales">Open report</a>', $html);
        $this->assertStringContainsString('Literal &lt;tag&gt; &amp; symbols', $html);
        $this->assertStringContainsString('&lt;img src=x onerror=alert(1)&gt;', $html);
        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringNotContainsString('**', $html);
        $this->assertStringNotContainsString('javascript:', $html);
    }

    public function test_telegram_html_formatter_preserves_sanitized_html_and_plain_text_messages(): void
    {
        AiSetting::current()->forceFill(['telegram_bot_token' => '91002:bot-secret'])->save();
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => []])]);
        $link = new AiChannelLink(['channel' => 'telegram', 'external_id' => '91002']);
        $client = app(TelegramClient::class);
        $client->sendText($link, '<b onclick="alert(1)">Already bold</b> <a href="javascript:alert(1)">unsafe</a>');
        $client->sendText($link, 'Ordinary text: < 2, > 1 & safe.');

        $payloads = Http::recorded(fn ($request) => str_contains($request->url(), 'sendMessage'))
            ->map(fn (array $pair) => $pair[0]->data())
            ->values();
        $this->assertSame('<b>Already bold</b> unsafe', $payloads[0]['text']);
        $this->assertSame('HTML', $payloads[0]['parse_mode']);
        $this->assertSame('Ordinary text: &lt; 2, &gt; 1 &amp; safe.', $payloads[1]['text']);
        $this->assertSame('HTML', $payloads[1]['parse_mode']);
    }

    public function test_telegram_long_formatted_messages_are_chunked_without_unbalanced_html(): void
    {
        AiSetting::current()->forceFill(['telegram_bot_token' => '91002:bot-secret'])->save();
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => []])]);
        $link = new AiChannelLink(['channel' => 'telegram', 'external_id' => '91002']);
        $plain = str_repeat('long message & <content> 🌿 ', 400);

        app(TelegramClient::class)->sendText($link, '**Long report**'."\n\n```text\n".$plain."\n```");

        $payloads = Http::recorded(fn ($request) => str_contains($request->url(), 'sendMessage'))
            ->map(fn (array $pair) => $pair[0]->data())
            ->values();
        $this->assertGreaterThan(1, $payloads->count());
        foreach ($payloads as $payload) {
            $this->assertSame('HTML', $payload['parse_mode']);
            $visible = html_entity_decode(strip_tags($payload['text']), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $this->assertLessThanOrEqual(3500, mb_strlen($visible));
            $this->assertLessThanOrEqual(
                3500,
                intdiv(strlen(mb_convert_encoding($visible, 'UTF-16LE', 'UTF-8')), 2),
            );
            $this->assertSame(
                substr_count($payload['text'], '<pre>'),
                substr_count($payload['text'], '</pre>'),
            );
            $this->assertSame(
                substr_count($payload['text'], '<b>'),
                substr_count($payload['text'], '</b>'),
            );
        }
        $fallbackChunks = app(TelegramHtmlFormatter::class)->chunks(
            '**Long report**'."\n\n```text\n".$plain."\n```",
        );
        $this->assertSame(
            'Long report'."\n\n".$plain."\n",
            implode('', array_column($fallbackChunks, 'plain')),
        );
    }

    public function test_telegram_retries_only_the_rejected_html_chunk_as_plain_text(): void
    {
        AiSetting::current()->forceFill(['telegram_bot_token' => '91002:bot-secret'])->save();
        Http::fakeSequence('api.telegram.org/*')
            ->push(['ok' => false, 'description' => "Bad Request: can't parse entities: Can't find end of the entity"], 400)
            ->push(['ok' => true, 'result' => ['message_id' => 1]], 200);
        $link = new AiChannelLink(['channel' => 'telegram', 'external_id' => '91002']);

        app(TelegramClient::class)->sendText($link, '**Safe answer** with <literal> & text');

        $requests = Http::recorded(fn ($request) => str_contains($request->url(), 'sendMessage'));
        $this->assertCount(2, $requests);
        $this->assertSame('HTML', $requests[0][0]->data()['parse_mode']);
        $this->assertStringContainsString('<b>Safe answer</b>', $requests[0][0]->data()['text']);
        $this->assertArrayNotHasKey('parse_mode', $requests[1][0]->data());
        $this->assertSame('Safe answer with <literal> & text', $requests[1][0]->data()['text']);
    }

    public function test_telegram_webhook_rejects_local_http_url_without_contacting_api(): void
    {
        AiSetting::current()->forceFill([
            'telegram_bot_token' => '12345:telegram-token-secret',
            'telegram_webhook_secret' => 'telegram-webhook-secret',
        ])->save();
        config(['app.url' => 'http://127.0.0.1:8000']);

        try {
            app(TelegramClient::class)->registerWebhook();
            $this->fail('A local HTTP webhook URL must be rejected.');
        } catch (\RuntimeException $error) {
            $this->assertStringNotContainsString('telegram-token-secret', $error->getMessage());
        }
        Http::assertNothingSent();
    }

    public function test_telegram_webhook_logs_safe_api_diagnostics(): void
    {
        AiSetting::current()->forceFill([
            'telegram_bot_token' => '12345:telegram-token-secret',
            'telegram_webhook_secret' => 'telegram-webhook-secret',
        ])->save();
        config(['app.url' => 'https://cultiv.id']);
        Http::fake([
            'api.telegram.org/*' => Http::response([
                'ok' => false,
                'description' => 'Bad webhook url=https://cultiv.id/ telegram-token-secret telegram-webhook-secret',
            ], 400),
        ]);
        $loggedMessage = null;
        $loggedContext = [];
        Log::shouldReceive('warning')->once()->withArgs(function ($message, $context) use (&$loggedMessage, &$loggedContext): bool {
            $loggedMessage = $message;
            $loggedContext = $context;

            return true;
        });

        try {
            app(TelegramClient::class)->registerWebhook();
            $this->fail('A rejected Telegram API request must be reported.');
        } catch (\RuntimeException $error) {
            $this->assertStringNotContainsString('telegram-token-secret', $error->getMessage());
            $this->assertStringNotContainsString('telegram-webhook-secret', $error->getMessage());
            $this->assertSame('Telegram request failed with HTTP 400.', $error->getMessage());
        }

        $contextJson = json_encode($loggedContext);
        $this->assertSame('ai.telegram.api_request_failed', $loggedMessage);
        $this->assertSame('setWebhook', $loggedContext['method'] ?? null);
        $this->assertSame(400, $loggedContext['http_status'] ?? null);
        $this->assertStringContainsString('[redacted]', $loggedContext['description'] ?? '');
        $this->assertStringNotContainsString('telegram-token-secret', $contextJson);
        $this->assertStringNotContainsString('telegram-webhook-secret', $contextJson);
    }

    public function test_telegram_webhook_registration_uses_the_configured_public_https_url(): void
    {
        AiSetting::current()->forceFill([
            'telegram_bot_token' => '12345:telegram-token-secret',
            'telegram_webhook_secret' => 'telegram-webhook-secret',
        ])->save();
        config(['app.url' => 'https://cultiv.id']);
        Http::fake([
            'api.telegram.org/*' => Http::response(['ok' => true, 'result' => true]),
        ]);

        app(TelegramClient::class)->registerWebhook();

        Http::assertSent(fn ($request) => str_contains($request->url(), 'setWebhook')
            && ($request->data()['url'] ?? null) === 'https://cultiv.id/api/ai/telegram/webhook'
            && ($request->data()['secret_token'] ?? null) === 'telegram-webhook-secret');
    }

    public function test_global_module_status_is_enforced_for_marketplace_and_routes(): void
    {
        $pos = Module::query()->where('key', 'pos')->firstOrFail();
        app(ModuleManager::class)->activate($pos, $this->tenant);

        $admin = User::create([
            'name' => 'Platform Admin',
            'email' => 'module-admin@test.dev',
            'password' => Hash::make('password'),
            'email_verified_at' => now(),
        ]);
        $admin->forceFill(['is_platform_admin' => true])->save();

        $this->actingAs($admin)->get('/admin/modules')->assertOk();
        $this->actingAs($admin)->put('/admin/modules/pos', ['availability_status' => 'maintenance'])
            ->assertRedirect('/admin/modules');
        $this->assertDatabaseHas('modules', [
            'id' => $pos->id,
            'availability_status' => 'maintenance',
            'is_active' => false,
        ]);

        $this->actingAs($this->owner)->withSession(['tenant_id' => $this->tenant->id])
            ->get('/modules')->assertOk()->assertSee('Under maintenance')
            ->assertDontSee('/modules/pos/activate');
        $this->actingAs($this->owner)->withSession(['tenant_id' => $this->tenant->id])
            ->get('/pos')->assertRedirect('/modules');

        $this->actingAs($admin)->put('/admin/modules/pos', ['availability_status' => 'hidden'])
            ->assertRedirect('/admin/modules');
        $this->actingAs($this->owner)->withSession(['tenant_id' => $this->tenant->id])
            ->get('/modules')->assertOk()->assertDontSee('Point of Sale (POS)');
        $this->actingAs($this->owner)->withSession(['tenant_id' => $this->tenant->id])
            ->get('/modules/pos')->assertNotFound();
    }

    public function test_telegram_link_code_is_one_time_and_bound_to_user_and_workspace(): void
    {
        AiSetting::current()->forceFill(['telegram_bot_token' => '123:test'])->save();
        $this->actingAs($this->owner)->withSession(['tenant_id' => $this->tenant->id])
            ->post('/settings/ai-channel/link-code')
            ->assertRedirect('/settings/ai-channel');

        $code = session('telegram_link_code');
        $this->assertIsString($code);
        $pending = AiChannelLink::query()->where('tenant_id', $this->tenant->id)->firstOrFail();
        $this->assertNotSame($code, $pending->link_token_hash);
        $this->assertTrue(app(TelegramLinkService::class)->redeem($code, 'telegram-user-1'));
        $this->assertFalse(app(TelegramLinkService::class)->redeem($code, 'telegram-user-2'));
        $this->assertSame('telegram-user-1', $pending->fresh()->external_id);
        $this->assertDatabaseHas('audit_logs', ['action' => 'ai.telegram.link_code_issued']);
    }

    public function test_provider_manager_supports_gemini_ollama_and_primary_fallback(): void
    {
        $settings = AiSetting::current();
        $settings->forceFill([
            'provider' => 'gemini',
            'model' => 'gemini-2.5-flash-lite',
            'gemini_api_key' => 'gemini-secret',
            'fallback_provider' => null,
            'fallback_model' => null,
        ])->save();
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [['content' => ['parts' => [['text' => 'Gemini OK']]]]],
            ]),
        ]);
        $this->assertSame('Gemini OK', app(AiProviderManager::class)->testPrimary()->text);

        $settings->forceFill([
            'provider' => 'ollama',
            'model' => 'qwen2.5:7b',
            'ollama_base_url' => 'http://127.0.0.1:11434',
        ])->save();
        Http::fake([
            '127.0.0.1:11434/api/chat' => Http::response([
                'message' => ['content' => 'Ollama OK'],
            ]),
        ]);
        $this->assertSame('Ollama OK', app(AiProviderManager::class)->testPrimary()->text);
    }

    public function test_provider_manager_uses_fallback_when_primary_fails(): void
    {
        AiSetting::current()->forceFill([
            'provider' => 'openai',
            'model' => 'gpt-4o-mini',
            'openai_api_key' => 'openai-secret',
            'gemini_api_key' => 'gemini-secret',
            'fallback_provider' => 'gemini',
            'fallback_model' => 'gemini-2.5-flash-lite',
        ])->save();
        Http::fake([
            'api.openai.com/*' => Http::response([], 503),
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [['content' => ['parts' => [['text' => 'Fallback OK']]]]],
            ]),
        ]);
        $this->assertSame('Fallback OK', app(AiProviderManager::class)->complete([
            ['role' => 'user', 'content' => 'Reply OK.'],
        ], [])->text);
    }

    public function test_telegram_webhook_checks_secret_and_deduplicates_usage(): void
    {
        AiChannelLink::create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $this->owner->id,
            'channel' => 'telegram',
            'external_id' => '91002',
            'linked_at' => now(),
        ]);
        AiSetting::current()->forceFill([
            'provider' => 'openai',
            'model' => 'gpt-4o-mini',
            'openai_api_key' => 'openai-secret',
            'telegram_bot_token' => '91002:bot-secret',
            'telegram_webhook_secret' => 'webhook-secret',
        ])->save();

        $payload = [
            'update_id' => 22001,
            'message' => [
                'chat' => ['id' => 91002, 'type' => 'private'],
                'from' => ['id' => 91002, 'is_bot' => false],
                'text' => 'Hello',
            ],
        ];
        $this->postJson('/api/ai/telegram/webhook', $payload)->assertForbidden();

        Http::fake([
            'api.openai.com/*' => Http::response([
                'choices' => [['message' => ['content' => 'Hello from AI']]],
            ]),
            'api.telegram.org/*' => Http::response(['ok' => true, 'result' => []]),
        ]);
        $headers = ['X-Telegram-Bot-Api-Secret-Token' => 'webhook-secret'];
        $this->postJson('/api/ai/telegram/webhook', $payload, $headers)->assertOk();
        $this->postJson('/api/ai/telegram/webhook', $payload, $headers)->assertOk();

        $this->assertSame(1, UsageRecord::withoutGlobalScopes()
            ->where('tenant_id', $this->tenant->id)->where('metric', 'ai_messages')->value('value'));
        Http::assertSentCount(2);
        Http::assertSent(fn ($request) => str_contains($request->url(), 'api.telegram.org')
            && ($request->data()['text'] ?? null) === 'Hello from AI');
    }

    public function test_telegram_callback_reports_confirmation_failures_without_claiming_success(): void
    {
        AiChannelLink::create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $this->owner->id,
            'channel' => 'telegram',
            'external_id' => '91002',
            'linked_at' => now(),
        ]);
        AiSetting::current()->forceFill([
            'telegram_bot_token' => '91002:bot-secret',
            'telegram_webhook_secret' => 'webhook-secret',
        ])->save();

        $sales = \Mockery::mock(AiPendingSaleService::class);
        $sales->shouldReceive('decide')->once()->andThrow(new \RuntimeException('Database unavailable'));
        $this->app->instance(AiPendingSaleService::class, $sales);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => true])]);

        $failureMessage = __('I could not process that request. Please try again later.');
        $response = $this->postJson('/api/ai/telegram/webhook', [
            'update_id' => 22002,
            'callback_query' => [
                'id' => 'callback-1',
                'from' => ['id' => 91002],
                'message' => ['chat' => ['id' => 91002, 'type' => 'private']],
                'data' => 'confirm_sale:'.Str::uuid(),
            ],
        ], ['X-Telegram-Bot-Api-Secret-Token' => 'webhook-secret']);

        $response->assertOk();
        Http::assertSent(fn ($request) => str_contains($request->url(), 'answerCallbackQuery')
            && ($request->data()['text'] ?? null) === $failureMessage);
        Http::assertSent(fn ($request) => str_contains($request->url(), 'sendMessage')
            && ($request->data()['text'] ?? null) === $failureMessage);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'answerCallbackQuery')
            && ($request->data()['text'] ?? null) === __('Sale confirmation processed.'));
    }

    public function test_ai_message_quota_uses_plan_entitlement(): void
    {
        $this->seed(PlanSeeder::class);
        $free = Plan::query()->where('is_free_tier', true)->firstOrFail();
        $entitlements = $free->entitlements;
        $entitlements['max_ai_messages'] = 1;
        $free->forceFill(['entitlements' => $entitlements])->save();

        $usage = app(UsageService::class);
        $usage->consume($this->tenant, 'ai_messages');
        $this->assertSame(1, $usage->usage($this->tenant, 'ai_messages'));
        $this->expectException(SubscriptionLimitException::class);
        $usage->consume($this->tenant, 'ai_messages');
    }

    public function test_ai_tools_are_tenant_scoped_and_sales_require_confirmation(): void
    {
        $this->contextAsOwner();
        $link = AiChannelLink::create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $this->owner->id,
            'channel' => 'telegram',
            'external_id' => 'telegram-owner',
            'linked_at' => now(),
        ]);

        $foreignOwner = User::create([
            'name' => 'Other Owner', 'email' => 'other-ai@test.dev',
            'password' => Hash::make('password'), 'email_verified_at' => now(),
        ]);
        $foreignTenant = Tenant::create([
            'name' => 'Other Workspace', 'slug' => 'other-ai-workspace',
            'owner_id' => $foreignOwner->id, 'status' => 'active',
        ]);
        $foreignTenant->users()->attach($foreignOwner->id, [
            'role' => 'Owner', 'status' => 'active', 'joined_at' => now(),
        ]);
        Product::create([
            'tenant_id' => $foreignTenant->id, 'name' => 'Secret Product',
            'sku' => 'SECRET-1', 'selling_price' => 100,
        ]);

        $search = app(BusinessAiTools::class)->execute('search_products', ['query' => 'Coffee'], $link);
        $this->assertStringContainsString('Arabica Coffee', $search->content);
        $this->assertStringNotContainsString('Secret Product', $search->content);

        $draft = app(BusinessAiTools::class)->execute('draft_sale', [
            'items' => [['product_id' => $this->product->id, 'quantity' => 2]],
        ], $link);
        $this->assertNotNull($draft->pendingAction);
        $this->assertSame(0, Sale::withoutGlobalScopes()->count());

        $recorded = app(AiPendingSaleService::class)->decide($draft->pendingAction->id, $link, true);
        $this->assertStringContainsString('was recorded', $recorded);
        $this->assertSame(1, Sale::withoutGlobalScopes()->count());
        $this->assertSame(6, StockBalance::withoutGlobalScopes()->where('product_id', $this->product->id)->value('quantity'));
        $this->assertSame('completed', $draft->pendingAction->fresh()->status);

        app(AiPendingSaleService::class)->decide($draft->pendingAction->id, $link, true);
        $this->assertSame(1, Sale::withoutGlobalScopes()->count());
    }

    public function test_ai_sale_draft_aggregates_stock_requirements_and_skips_untracked_products(): void
    {
        $this->contextAsOwner();
        $link = AiChannelLink::create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $this->owner->id,
            'channel' => 'telegram',
            'external_id' => 'telegram-stock-test',
            'linked_at' => now(),
        ]);
        StockBalance::query()->where('product_id', $this->product->id)->update(['quantity' => 3]);

        $insufficient = app(BusinessAiTools::class)->execute('draft_sale', [
            'items' => [
                ['product_id' => $this->product->id, 'quantity' => 2],
                ['product_id' => $this->product->id, 'quantity' => 2],
            ],
        ], $link);
        $this->assertNull($insufficient->pendingAction);
        $this->assertStringContainsString('Available: 3', $insufficient->content);
        $this->assertSame(0, AiPendingAction::query()->count());

        $untracked = Product::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Service Item',
            'sku' => 'SVC-001',
            'selling_price' => 5000,
            'track_inventory' => false,
            'is_active' => true,
        ]);
        app()->setLocale('id');
        $draft = app(BusinessAiTools::class)->execute('draft_sale', [
            'items' => [['product_id' => $untracked->id, 'quantity' => 1]],
            'payment_amount' => 0,
        ], $link);

        $this->assertNotNull($draft->pendingAction);
        $this->assertStringContainsString('Jumlah pembayaran:', $draft->content);
        $this->assertStringNotContainsString('Pembayaran tercatat:', $draft->content);
        app()->setLocale('en');
    }

    public function test_module_status_admin_page_and_ai_settings_are_localized_in_id(): void
    {
        $this->owner->forceFill(['locale' => 'id'])->save();
        $this->actingAs($this->owner)->withSession(['tenant_id' => $this->tenant->id])
            ->get('/modules')->assertOk()
            ->assertSee('AI Assistant Telegram')
            ->assertSee('Kelola bisnis workspace melalui Telegram dengan bantuan AI.');
        $this->assertSame(
            'AI Assistant Telegram membantu Anda menanyakan hal seputar bisnis workspace melalui akun Telegram yang sudah ditautkan.',
            __('AI Assistant Telegram lets you ask workspace business questions through a linked Telegram account.', [], 'id')
        );

        $admin = User::create([
            'name' => 'Platform Admin',
            'email' => 'ai-localized-admin@test.dev',
            'password' => Hash::make('password'),
            'email_verified_at' => now(),
            'locale' => 'id',
        ]);
        $admin->forceFill(['is_platform_admin' => true])->save();
        $this->actingAs($admin)->get('/admin/ai')->assertOk()->assertSee('Asisten AI');
    }
}
