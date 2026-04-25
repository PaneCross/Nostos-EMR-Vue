<?php

namespace App\Services\Eligibility;

use App\Models\Participant;

class ChangeHealthcareEligibilityGateway implements EligibilityGateway
{
    public function name(): string { return 'change_healthcare'; }

    public function check(Participant $participant, string $payerType, ?string $memberId): array
    {
        throw new \RuntimeException('ChangeHealthcareEligibilityGateway: awaiting clearinghouse contract + Change Healthcare API credentials.');
    }

    public function healthCheck(): bool { return false; }
}
