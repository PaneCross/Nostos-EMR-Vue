<?php

// ─── HrisWebhookController ───────────────────────────────────────────────────
// Phase 15.7 : Scaffold endpoint that receives HRIS webhooks
// (BambooHR / Rippling / Gusto / custom). Records the event row; does NOT
// yet auto-sync into the credentialing system. When a real HRIS contract
// lands, wire the provider-specific processor in HrisSyncService.
//
// Per-tenant URL: POST /webhooks/hris/{tenantId}/{provider}
// HMAC signature header (vendor-specific): X-Hris-Signature or X-Signature.
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\HrisConfig;
use App\Models\HrisEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class HrisWebhookController extends Controller
{
    public function receive(Request $request, int $tenantId, string $provider): JsonResponse
    {
        if (! in_array($provider, HrisConfig::PROVIDERS, true)) {
            return response()->json(['error' => 'unsupported_provider'], 400);
        }

        $config = HrisConfig::forTenant($tenantId)->active()
            ->where('provider', $provider)->first();

        // Scaffold behavior: accept webhooks even without active config (so we
        // can record early integration-test traffic). Real adapter WILL 401
        // when no config exists. Marked "received" and "ignored" status to be
        // honest about what happened.
        $signatureHeader = $request->header('X-Hris-Signature')
                        ?? $request->header('X-Signature')
                        ?? '';
        $payload = $request->getContent();
        $verified = $config && $signatureHeader
            ? $config->verifySecret($signatureHeader, $payload)
            : false;

        $event = HrisEvent::create([
            'tenant_id'         => $tenantId,
            'hris_config_id'    => $config?->id,
            'provider'          => $provider,
            'event_type'        => (string) $request->input('event_type', 'unknown'),
            'payload'           => $request->all(),
            'processing_status' => $verified ? 'received' : 'ignored',
            'processing_notes'  => $verified
                ? 'Signature verified. Scaffold : no auto-sync to credentialing yet.'
                : 'No active config or signature missing/invalid : event stored for audit only.',
            'received_at'       => now(),
        ]);

        if ($config) {
            $config->update(['last_event_at' => now()]);
        }

        AuditLog::record(
            action: 'hris.webhook_received',
            tenantId: $tenantId,
            resourceType: 'hris_event',
            resourceId: $event->id,
            description: "HRIS webhook: {$provider}/{$event->event_type} → {$event->processing_status}",
        );

        return response()->json([
            'ok'         => true,
            'event_id'   => $event->id,
            'status'     => $event->processing_status,
            'honest_label' => 'HRIS sync is scaffolded but not wired. Event recorded for audit; '
                            . 'credentialing table not modified. Activation requires signed vendor agreement + adapter implementation.',
        ], 202);
    }
}
