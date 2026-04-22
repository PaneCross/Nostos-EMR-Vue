<?php

// ─── NullClearinghouseGateway ────────────────────────────────────────────────
// Phase 12. Honest-label default. Does NOT transmit anywhere. Each outbound
// call stages the EDI content on the filesystem / S3 (via Laravel Storage)
// and writes a ClearinghouseTransmission row with status='staged_manual' so
// the operator can upload the file to whatever web portal they actually use.
//
// This is the ONLY gateway active in the demo EMR. Activating a real vendor
// adapter requires a paid contract + IT admin toggling the tenant's config.
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Services\Clearinghouse;

use App\Models\ClearinghouseConfig;
use App\Models\ClearinghouseTransmission;
use App\Models\EdiBatch;
use Illuminate\Support\Facades\Storage;

class NullClearinghouseGateway implements ClearinghouseGateway
{
    public function name(): string
    {
        return 'null_gateway';
    }

    public function transmitClaimBatch(EdiBatch $batch, ClearinghouseConfig $cfg): ClearinghouseTransmissionResult
    {
        // Save the EDI content to a well-known staging location per tenant+batch
        $path = sprintf('clearinghouse-staging/tenant-%d/batch-%d-%s.x12',
            $batch->tenant_id, $batch->id, now()->format('Ymd-His'));

        try {
            Storage::disk('local')->put($path, $batch->edi_content ?? '');
        } catch (\Throwable $e) {
            // Non-fatal — we still log the transmission; the operator can
            // retrieve content via the controller endpoint.
        }

        $row = ClearinghouseTransmission::create([
            'tenant_id'        => $batch->tenant_id,
            'edi_batch_id'     => $batch->id,
            'config_id'        => $cfg->id,
            'adapter'          => 'null_gateway',
            'direction'        => 'outbound',
            'transaction_kind' => '837P',
            'status'           => 'staged_manual',
            'attempted_at'     => now(),
            'completed_at'     => now(),
            'attempt_number'   => 1,
            'raw_payload'      => "Staged for manual upload at: {$path}",
        ]);

        return new ClearinghouseTransmissionResult(
            status: 'staged_manual',
            vendorTransactionId: null,
            message: "No vendor configured. EDI staged for manual upload: {$path}",
            transmissionId: $row->id,
        );
    }

    public function pollStatus(EdiBatch $batch, ClearinghouseConfig $cfg): ClearinghouseTransmissionResult
    {
        $row = ClearinghouseTransmission::create([
            'tenant_id'        => $batch->tenant_id,
            'edi_batch_id'     => $batch->id,
            'config_id'        => $cfg->id,
            'adapter'          => 'null_gateway',
            'direction'        => 'outbound',
            'transaction_kind' => 'status_poll',
            'status'           => 'staged_manual',
            'attempted_at'     => now(),
            'completed_at'     => now(),
            'attempt_number'   => 1,
            'raw_payload'      => 'Null gateway — no remote status available.',
        ]);

        return new ClearinghouseTransmissionResult(
            status: 'staged_manual',
            message: 'Null gateway — status tracked manually.',
            transmissionId: $row->id,
        );
    }

    public function fetchAcknowledgments(ClearinghouseConfig $cfg): int
    {
        return 0; // nothing to fetch; operator manually uploads 277CA text when received
    }

    public function fetchRemittance(ClearinghouseConfig $cfg): int
    {
        return 0; // operator manually uploads 835 files
    }

    public function healthCheck(ClearinghouseConfig $cfg): bool
    {
        return true;
    }
}
