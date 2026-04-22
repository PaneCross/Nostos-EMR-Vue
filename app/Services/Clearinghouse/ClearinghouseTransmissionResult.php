<?php

namespace App\Services\Clearinghouse;

// ─── ClearinghouseTransmissionResult ─────────────────────────────────────────
// Phase 12. Small DTO returned by ClearinghouseGateway methods. Keeps the
// core application decoupled from vendor-specific response shapes.
// ─────────────────────────────────────────────────────────────────────────────

final class ClearinghouseTransmissionResult
{
    public function __construct(
        public readonly string $status,            // matches emr_clearinghouse_transmissions.status
        public readonly ?string $vendorTransactionId = null,
        public readonly ?string $message = null,
        public readonly ?int $transmissionId = null, // the DB row we just wrote
    ) {}

    public function succeeded(): bool
    {
        return in_array($this->status, ['submitted', 'accepted', 'staged_manual'], true);
    }
}
