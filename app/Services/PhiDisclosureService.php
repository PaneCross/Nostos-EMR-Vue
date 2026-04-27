<?php

// ─── PhiDisclosureService : Phase P2 ────────────────────────────────────────
// Helper to record a HIPAA Accounting of Disclosures entry. Callers anywhere
// PHI leaves the EMR should call ::record(...) : ROI fulfillment, EHI export
// download, FHIR API access, HIE publish, CCDA export, etc.
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Services;

use App\Models\PhiDisclosure;
use Illuminate\Database\Eloquent\Model;

class PhiDisclosureService
{
    public function record(
        int $tenantId,
        int $participantId,
        string $recipientType,
        string $recipientName,
        string $purpose,
        string $method,
        string $recordsDescribed,
        ?int $disclosedByUserId = null,
        ?string $recipientContact = null,
        ?Model $related = null,
    ): PhiDisclosure {
        return PhiDisclosure::create([
            'tenant_id'           => $tenantId,
            'participant_id'      => $participantId,
            'disclosed_at'        => now(),
            'disclosed_by_user_id'=> $disclosedByUserId,
            'recipient_type'      => $recipientType,
            'recipient_name'      => $recipientName,
            'recipient_contact'   => $recipientContact,
            'disclosure_purpose'  => $purpose,
            'disclosure_method'   => $method,
            'records_described'   => $recordsDescribed,
            'related_to_type'     => $related ? $related::class : null,
            'related_to_id'       => $related?->getKey(),
        ]);
    }
}
