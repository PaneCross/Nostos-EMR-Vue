<?php

// ─── AvailityClearinghouseGateway ────────────────────────────────────────────
// Phase 12. Stub adapter for Availity. Availity exposes a REST API for claims
// submission, status polling, and ERA retrieval. Activation requires a signed
// Availity Essentials / Portal+ trading partner agreement and a submitter ID.
//
// IMPLEMENTATION NOTES (for the post-contract build):
//   POST {endpoint}/availity/v1/coverages          (eligibility 270/271)
//   POST {endpoint}/availity/v1/claims/submissions (wraps 837P)
//   GET  {endpoint}/availity/v1/claims/submissions/{id}
//   GET  {endpoint}/availity/v1/remittances        (pulls 835s)
//   OAuth2 bearer (client_credentials); tokens expire ~1h.
//
// Until that lands, every method throws a helpful runtime exception.
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Services\Clearinghouse;

use App\Models\ClearinghouseConfig;
use App\Models\EdiBatch;
use RuntimeException;

class AvailityClearinghouseGateway implements ClearinghouseGateway
{
    public function name(): string
    {
        return 'availity';
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
        // Return false rather than throwing so admin UI can surface
        // "not wired yet" instead of crashing.
        return false;
    }

    private function notWired(string $method): RuntimeException
    {
        return new RuntimeException(
            "AvailityClearinghouseGateway::{$method}() is a scaffold. "
            . "Activation requires a signed Availity trading-partner agreement + submitter ID. "
            . "See memory file feedback_clearinghouse_gateway.md for the post-contract build checklist."
        );
    }
}
