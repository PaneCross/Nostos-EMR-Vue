<?php

// ─── OfficeAllyClearinghouseGateway ──────────────────────────────────────────
// Phase 12. Stub adapter for Office Ally. SFTP is the default transport
// (smaller vendor, no REST API). Activation requires a signed OA enrollment.
//
// IMPLEMENTATION NOTES (for the post-contract build):
//   SFTP: ftp10.officeally.com  (configurable per tenant)
//   Directories: /inbox for outbound 837P, /outbox for 277/999/835 pickup
//   Auth: SSH key (preferred) or password.
//
// Until wired, every method throws.
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Services\Clearinghouse;

use App\Models\ClearinghouseConfig;
use App\Models\EdiBatch;
use RuntimeException;

class OfficeAllyClearinghouseGateway implements ClearinghouseGateway
{
    public function name(): string
    {
        return 'office_ally';
    }

    public function transmitClaimBatch(EdiBatch $batch, ClearinghouseConfig $cfg): ClearinghouseTransmissionResult
    {
        throw $this->notWired('transmitClaimBatch');
    }

    public function pollStatus(EdiBatch $batch, ClearinghouseConfig $cfg): ClearinghouseTransmissionResult
    {
        throw $this->notWired('pollStatus');
    }

    public function fetchAcknowledgments(ClearinghouseConfig $cfg): int
    {
        throw $this->notWired('fetchAcknowledgments');
    }

    public function fetchRemittance(ClearinghouseConfig $cfg): int
    {
        throw $this->notWired('fetchRemittance');
    }

    public function healthCheck(ClearinghouseConfig $cfg): bool
    {
        return false;
    }

    private function notWired(string $method): RuntimeException
    {
        return new RuntimeException(
            "OfficeAllyClearinghouseGateway::{$method}() is a scaffold. "
            . "Activation requires a signed Office Ally enrollment + SFTP key provisioning. "
            . "See memory file feedback_clearinghouse_gateway.md for the post-contract build checklist."
        );
    }
}
