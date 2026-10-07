<?php

namespace App\Http\Controllers;

use App\Models\AiChannelLink;
use App\Models\AiSetting;
use App\Services\Ai\AiAuditService;
use App\Services\Ai\TelegramLinkService;
use App\Services\ModuleManager;
use App\Services\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AiChannelController extends Controller
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly ModuleManager $modules,
        private readonly TelegramLinkService $links,
        private readonly AiAuditService $audit,
    ) {}

    public function edit(): View
    {
        $this->assertAgentActive();
        $tenant = $this->context->tenant();
        $user = $this->context->user();

        return view('settings.ai-channel', [
            'tenant' => $tenant,
            'telegramConfigured' => (bool) AiSetting::current()->telegram_bot_token,
            'link' => AiChannelLink::query()->where('tenant_id', $tenant->id)
                ->where('user_id', $user->id)->where('channel', 'telegram')
                ->whereNotNull('external_id')->first(),
        ]);
    }

    public function createLinkCode(): RedirectResponse
    {
        $this->assertAgentActive();
        $tenant = $this->context->tenant();
        $user = $this->context->user();
        if (! AiSetting::current()->telegram_bot_token) {
            return back()->with('status', ['type' => 'error', 'message' => __('Telegram is not configured for this platform yet.')]);
        }
        $existing = AiChannelLink::query()->where('tenant_id', $tenant->id)
            ->where('user_id', $user->id)->where('channel', 'telegram')->whereNotNull('external_id')->exists();

        if ($existing) {
            return back()->with('status', ['type' => 'error', 'message' => __('Unlink the existing Telegram account before creating another link code.')]);
        }

        $code = $this->links->createCode($tenant->id, $user->id);
        $this->audit->tenantEvent($tenant->id, $user->id, 'ai.telegram.link_code_issued', 'success');

        return redirect()->route('ai.channel.edit')->with('telegram_link_code', $code);
    }

    public function unlink(): RedirectResponse
    {
        $this->assertAgentActive();
        $tenant = $this->context->tenant();
        $user = $this->context->user();
        $query = AiChannelLink::query()->where('tenant_id', $tenant->id)
            ->where('user_id', $user->id)->where('channel', 'telegram')->whereNotNull('external_id');

        foreach ($query->get() as $link) {
            $this->audit->channel($link, 'ai.telegram.unlinked', 'success');
            $link->delete();
        }

        return redirect()->route('ai.channel.edit')
            ->with('status', ['type' => 'success', 'message' => __('Telegram account unlinked.')]);
    }

    private function assertAgentActive(): void
    {
        $this->context->check();
        abort_unless($this->modules->active('ai_agent', $this->context->tenant()), 404);
    }
}
