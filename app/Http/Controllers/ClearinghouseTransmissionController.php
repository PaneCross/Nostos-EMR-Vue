<?php

// ─── ClearinghouseTransmissionController ────────────────────────────────────
// Phase 12 (MVP roadmap). Bridges the existing Edi837PBuilderService + the
// vendor-agnostic ClearinghouseGatewayFactory so finance staff can:
//   1. Build an 837P batch from selected encounters
//   2. Transmit via the tenant's active gateway (or stage for manual upload
//      via the NullClearinghouseGateway default)
//   3. See the transmission history + status
//
// Honest labels: when the null gateway is active, UI responses include a
// "honest_label" field that says "staged for manual upload."
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\ClearinghouseTransmission;
use App\Models\EdiBatch;
use App\Services\Clearinghouse\ClearinghouseGatewayFactory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ClearinghouseTransmissionController extends Controller
{
    public function __construct(private ClearinghouseGatewayFactory $factory) {}

    private function gate(): void
    {
        $u = Auth::user();
        abort_if(!$u, 401);
        $ok = $u->isSuperAdmin()
            || in_array($u->department, ['finance', 'it_admin', 'qa_compliance']);
        abort_unless($ok, 403);
    }

    /**
     * POST /clearinghouse/batches/{batch}/transmit
     * Transmit (or stage, if null gateway) a pre-built 837P batch.
     */
    public function transmit(Request $request, EdiBatch $batch): JsonResponse
    {
        $this->gate();
        $u = Auth::user();
        abort_unless($batch->tenant_id === $u->tenant_id, 404);

        [$gateway, $cfg] = $this->factory->forTenant($u->tenant_id);

        try {
            $result = $gateway->transmitClaimBatch($batch, $cfg);
        } catch (\Throwable $e) {
            ClearinghouseTransmission::create([
                'tenant_id'        => $batch->tenant_id,
                'edi_batch_id'     => $batch->id,
                'config_id'        => $cfg->id,
                'adapter'          => $gateway->name(),
                'direction'        => 'outbound',
                'transaction_kind' => '837P',
                'status'           => 'error',
                'attempted_at'     => now(),
                'completed_at'     => now(),
                'attempt_number'   => 1,
                'error_message'    => $e->getMessage(),
            ]);
            AuditLog::record(
                action: 'clearinghouse.transmit_failed',
                tenantId: $u->tenant_id,
                userId: $u->id,
                resourceType: 'edi_batch',
                resourceId: $batch->id,
                description: 'Clearinghouse transmit failed: ' . substr($e->getMessage(), 0, 200),
            );
            return response()->json([
                'ok'      => false,
                'message' => $e->getMessage(),
                'adapter' => $gateway->name(),
            ], 503);
        }

        AuditLog::record(
            action: 'clearinghouse.transmitted',
            tenantId: $u->tenant_id,
            userId: $u->id,
            resourceType: 'edi_batch',
            resourceId: $batch->id,
            description: "Clearinghouse transmit: adapter={$gateway->name()} status={$result->status}",
        );

        $body = [
            'ok'       => $result->succeeded(),
            'status'   => $result->status,
            'adapter'  => $gateway->name(),
            'message'  => $result->message,
            'vendor_transaction_id' => $result->vendorTransactionId,
            'transmission_id'       => $result->transmissionId,
        ];
        if ($gateway->name() === 'null_gateway') {
            $body['honest_label'] = 'No vendor contract active — 837P staged for manual upload. '
                . 'When a clearinghouse contract lands, IT admin activates the tenant config and this flow transmits automatically.';
        }
        return response()->json($body, $result->succeeded() ? 200 : 503);
    }

    /**
     * GET /clearinghouse/batches/{batch}/transmissions
     */
    public function history(EdiBatch $batch): JsonResponse
    {
        $this->gate();
        $u = Auth::user();
        abort_unless($batch->tenant_id === $u->tenant_id, 404);

        $rows = ClearinghouseTransmission::forTenant($u->tenant_id)
            ->where('edi_batch_id', $batch->id)
            ->orderByDesc('attempted_at')
            ->limit(100)
            ->get(['id', 'adapter', 'direction', 'transaction_kind', 'vendor_transaction_id',
                   'status', 'attempted_at', 'completed_at', 'error_message', 'attempt_number']);

        return response()->json(['transmissions' => $rows]);
    }
}
