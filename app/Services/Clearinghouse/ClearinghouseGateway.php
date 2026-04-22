<?php

// ─── ClearinghouseGateway (interface) ────────────────────────────────────────
// Phase 12 (MVP roadmap). Vendor-agnostic contract for claims-clearinghouse
// adapters. Real vendor adapters (Availity, Change Healthcare, Office Ally)
// implement this; the NullClearinghouseGateway is the safe default when no
// vendor contract is active.
//
// All methods work with primitives + small DTOs so that the core application
// never depends on a vendor-specific HTTP client. Adapters own the protocol
// details (REST vs SFTP vs SOAP).
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Services\Clearinghouse;

use App\Models\ClearinghouseConfig;
use App\Models\EdiBatch;

interface ClearinghouseGateway
{
    public function name(): string;

    /**
     * Transmit an 837P batch.
     *
     * Implementations must:
     *   - Write a ClearinghouseTransmission row with direction='outbound',
     *     transaction_kind='837P' and an appropriate status.
     *   - Stamp the EdiBatch's status via the existing state machine.
     *
     * Null gateway: writes status='staged_manual' (file awaits manual upload).
     * Real gateway: writes status='submitted' when the vendor acknowledges
     * receipt, and returns the vendor's transaction identifier.
     *
     * @return ClearinghouseTransmissionResult
     */
    public function transmitClaimBatch(EdiBatch $batch, ClearinghouseConfig $cfg): ClearinghouseTransmissionResult;

    /**
     * Poll the vendor for status on an in-flight batch.
     * Writes a ClearinghouseTransmission status_poll row. Real adapters
     * update the EdiBatch status as new information arrives.
     */
    public function pollStatus(EdiBatch $batch, ClearinghouseConfig $cfg): ClearinghouseTransmissionResult;

    /**
     * Fetch any new 277CA / 999 acknowledgments available at the vendor.
     * Each acknowledgment is parsed through Edi837PBuilderService::parseAcknowledgement
     * and persisted as an inbound ClearinghouseTransmission.
     *
     * @return int count of acknowledgments fetched this run
     */
    public function fetchAcknowledgments(ClearinghouseConfig $cfg): int;

    /**
     * Fetch any new 835 remittance files available at the vendor.
     * Each file is handed to Remittance835ParserService via
     * Process835RemittanceJob (queued). Written as inbound transmission.
     *
     * @return int count of 835 files fetched this run
     */
    public function fetchRemittance(ClearinghouseConfig $cfg): int;

    /**
     * True if the configured credentials look valid.
     * Real adapters do an auth probe; null gateway always true.
     */
    public function healthCheck(ClearinghouseConfig $cfg): bool;
}
