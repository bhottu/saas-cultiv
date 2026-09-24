<?php

namespace App\Http\Controllers;

use App\Services\WebhookService;
use Illuminate\Http\Request;

class WebhookController extends Controller
{
    public function __construct(private readonly WebhookService $webhooks) {}

    /** POST /api/webhooks/qris — unauthenticated, signature-verified, idempotent. */
    public function qris(Request $request)
    {
        $payload = $request->json()->all();

        if (empty($payload)) {
            abort(400, 'Invalid JSON payload.');
        }

        $result = $this->webhooks->handle($payload, $request->getContent());

        return response()->json(['received' => true] + $result, 200);
    }
}
