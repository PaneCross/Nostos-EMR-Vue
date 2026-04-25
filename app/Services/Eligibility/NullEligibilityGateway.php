<?php

namespace App\Services\Eligibility;

use App\Models\Participant;

class NullEligibilityGateway implements EligibilityGateway
{
    public function name(): string { return 'null'; }

    public function check(Participant $participant, string $payerType, ?string $memberId): array
    {
        return [
            'status'  => 'unverified',
            'payload' => [
                'gateway' => 'null',
                'honest_label' => 'Real-time eligibility verification is scaffold-only until a clearinghouse contract is signed (paywall report item 16). Confirm eligibility manually via Medicare/Medicaid web portals.',
            ],
            'message' => 'Eligibility unverified (no gateway configured).',
        ];
    }

    public function healthCheck(): bool { return true; }
}
