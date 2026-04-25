<?php

namespace App\Services\Eligibility;

use App\Models\Participant;

/** Availity 270/271 stub. Throws until contracts + SDK land. */
class AvailityEligibilityGateway implements EligibilityGateway
{
    public function name(): string { return 'availity'; }

    public function check(Participant $participant, string $payerType, ?string $memberId): array
    {
        throw new \RuntimeException('AvailityEligibilityGateway: awaiting clearinghouse contract + Availity API credentials.');
    }

    public function healthCheck(): bool { return false; }
}
