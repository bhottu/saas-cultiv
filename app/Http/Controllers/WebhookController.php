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

    /**
     * POST /api/webhooks/kasera — unauthenticated, header-signed, idempotent.
     *
     * Kasera signs the RAW body (never a re-encoded array), so verification is handed
     * $request->getContent() byte-for-byte plus the signature header — re-encoding the
     * JSON first would make the HMAC disagree with what the provider actually signed.
     */
    public function kasera(Request $request)
    {
        $payload = $request->json()->all();

        if (empty($payload)) {
            abort(400, 'Invalid JSON payload.');
        }

        $signature = $request->header('Kasera-Signature-V1') ?: (string) $request->header('Kasera-Signature', '');

        $result = $this->webhooks->handleKasera($payload, $request->getContent(), $signature);

        return response()->json(['received' => true] + $result, 200);
    }
}
