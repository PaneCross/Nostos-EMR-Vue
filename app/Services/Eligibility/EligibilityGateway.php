<?php

// ─── EligibilityGateway (interface) : Phase P5 ──────────────────────────────
// Vendor-agnostic X12 270/271 contract. NullEligibilityGateway is the safe
// default; real adapters (Availity / Change Healthcare) implement once
// contracts + credentials land. Mirrors ClearinghouseGateway pattern.
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Services\Eligibility;

use App\Models\Participant;

interface EligibilityGateway
{
    public function name(): string;

    /**
     * Run a 270 eligibility request. Returns shape:
     *   ['status' => 'verified|inactive|denied|error|unverified',
     *    'payload' => array, 'message' => ?string]
     */
    public function check(Participant $participant, string $payerType, ?string $memberId): array;

    /** Auth/connection probe. Real gateway pings the vendor; null returns true. */
    public function healthCheck(): bool;
}
