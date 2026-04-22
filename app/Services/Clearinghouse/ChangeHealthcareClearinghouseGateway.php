<?php

// ─── ChangeHealthcareClearinghouseGateway ────────────────────────────────────
// Phase 12. Stub adapter for Change Healthcare (now Optum). REST + SFTP.
// Activation requires a signed CH Clearinghouse agreement and a provisioned
// submitter ID. Their REST API is the Medical Network API set; SFTP is
// available for larger volumes.
//
// IMPLEMENTATION NOTES (for the post-contract build):
//   REST: https://api.changehealthcare.com/medicalnetwork/professionalclaims/v3
//   SFTP: sftp.changehealthcare.com (configurable per tenant)
//   OAuth2 bearer (client_credentials).
//
// Until wired, every method throws.
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Services\Clearinghouse;

use App\Models\ClearinghouseConfig;
use App\Models\EdiBatch;
use RuntimeException;

class ChangeHealthcareClearinghouseGateway implements ClearinghouseGateway
{
    public function name(): string
    {
        return 'change_healthcare';
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
            "ChangeHealthcareClearinghouseGateway::{$method}() is a scaffold. "
            . "Activation requires a signed Change Healthcare (Optum) agreement. "
            . "See memory file feedback_clearinghouse_gateway.md for the post-contract build checklist."
        );
    }
}
