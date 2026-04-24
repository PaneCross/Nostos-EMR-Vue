<?php

// ─── HospiceService ──────────────────────────────────────────────────────────
// Phase C3. Hospice lifecycle on a PACE participant:
//   refer → enroll (auto-creates comfort-care order set) → record death
//   (schedules bereavement contacts + triggers disenrollment). Also
//   supports recording IDT reviews to keep the 6-month recert clock happy.
//
// All writes audit-log. Comfort-care order set is a fixed 5-order bundle
// defined in COMFORT_CARE_BUNDLE and created through ClinicalOrder so the
// existing order workflow (ack, complete, alerts) takes over.
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Services;

use App\Models\AuditLog;
use App\Models\BereavementContact;
use App\Models\ClinicalOrder;
use App\Models\Participant;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class HospiceService
{
    /**
     * Fixed comfort-care order set. Each entry becomes a ClinicalOrder.
     * order_type must exist in ClinicalOrder::ORDER_TYPES.
     */
    public const COMFORT_CARE_BUNDLE = [
        [
            'order_type'          => 'medication_change',
            'instructions'        => 'Hold all non-palliative medications. Retain only symptom-control drugs.',
            'clinical_indication' => 'Comfort-care transition',
        ],
        [
            'order_type'          => 'medication_change',
            'instructions'        => 'PRN pain management per palliative-care protocol (morphine PO/SL PRN pain).',
            'clinical_indication' => 'Comfort-care transition — pain',
        ],
        [
            'order_type'          => 'consult',
            'instructions'        => 'Confirm DNR / DNI status and document in advance-directive record. Scan signed POLST/MOLST into consents.',
            'clinical_indication' => 'Comfort-care transition — code status',
        ],
        [
            'order_type'          => 'consult',
            'instructions'        => 'Spiritual-care referral. Offer participant + family chaplain visit per preference.',
            'clinical_indication' => 'Comfort-care transition — spiritual',
        ],
        [
            'order_type'          => 'consult',
            'instructions'        => 'Schedule family meeting within 7 days to review goals of care and answer questions.',
            'clinical_indication' => 'Comfort-care transition — family',
        ],
    ];

    public function refer(Participant $p, User $user, array $data): Participant
    {
        $p->update([
            'hospice_status'         => 'referred',
            'hospice_provider_text'  => $data['hospice_provider_text'] ?? $p->hospice_provider_text,
            'hospice_diagnosis_text' => $data['hospice_diagnosis_text'] ?? $p->hospice_diagnosis_text,
        ]);
        AuditLog::record(
            action: 'hospice.referred',
            tenantId: $p->tenant_id,
            userId: $user->id,
            resourceType: 'participant',
            resourceId: $p->id,
            description: "Hospice referral initiated for participant #{$p->id}.",
        );
        return $p->fresh();
    }

    /**
     * Enroll in hospice. Creates the comfort-care order set atomically.
     */
    public function enroll(Participant $p, User $user, array $data): array
    {
        return DB::transaction(function () use ($p, $user, $data) {
            $started = isset($data['hospice_started_at'])
                ? Carbon::parse($data['hospice_started_at'])
                : now();

            $p->update([
                'hospice_status'             => 'enrolled',
                'hospice_started_at'         => $started,
                'hospice_last_idt_review_at' => $started, // initial IDT huddle at enrollment
                'hospice_provider_text'      => $data['hospice_provider_text'] ?? $p->hospice_provider_text,
                'hospice_diagnosis_text'     => $data['hospice_diagnosis_text'] ?? $p->hospice_diagnosis_text,
            ]);

            $orders = [];
            foreach (self::COMFORT_CARE_BUNDLE as $spec) {
                $orders[] = ClinicalOrder::create([
                    'participant_id'      => $p->id,
                    'tenant_id'           => $p->tenant_id,
                    'site_id'             => $p->site_id,
                    'ordered_by_user_id'  => $user->id,
                    'ordered_at'          => now(),
                    'order_type'          => $spec['order_type'],
                    'priority'            => 'urgent',
                    'status'              => 'pending',
                    'instructions'        => $spec['instructions'],
                    'clinical_indication' => $spec['clinical_indication'],
                    'target_department'   => ClinicalOrder::DEPARTMENT_ROUTING[$spec['order_type']] ?? 'primary_care',
                ]);
            }

            AuditLog::record(
                action: 'hospice.enrolled',
                tenantId: $p->tenant_id,
                userId: $user->id,
                resourceType: 'participant',
                resourceId: $p->id,
                description: "Hospice enrollment confirmed for participant #{$p->id}. Comfort-care bundle of " . count($orders) . " orders created.",
            );

            return ['participant' => $p->fresh(), 'orders' => $orders];
        });
    }

    /**
     * Record a hospice IDT review (weekly team huddle per hospice program).
     * Resets the 6-month IDT-review clock.
     */
    public function recordIdtReview(Participant $p, User $user, ?string $notes = null): Participant
    {
        $p->update(['hospice_last_idt_review_at' => now()]);
        AuditLog::record(
            action: 'hospice.idt_review',
            tenantId: $p->tenant_id,
            userId: $user->id,
            resourceType: 'participant',
            resourceId: $p->id,
            description: 'Hospice IDT review logged.' . ($notes ? " Notes: {$notes}" : ''),
        );
        return $p->fresh();
    }

    /**
     * Record participant death. Atomically:
     *   - Sets hospice_status = deceased
     *   - Sets enrollment_status = disenrolled, disenrollment_date, type=death, reason=death
     *   - Schedules 3 bereavement contacts (day 15 / 30 / month 3)
     *   - Writes audit log
     */
    public function recordDeath(
        Participant $p,
        User $user,
        Carbon $dateOfDeath,
        ?string $familyContactName = null,
        ?string $familyContactPhone = null,
    ): array {
        return DB::transaction(function () use ($p, $user, $dateOfDeath, $familyContactName, $familyContactPhone) {
            $p->update([
                'hospice_status'       => 'deceased',
                'enrollment_status'    => 'disenrolled',
                'disenrollment_date'   => $dateOfDeath->toDateString(),
                'disenrollment_type'   => 'death',
                'disenrollment_reason' => 'death',
            ]);

            $contacts = [];
            foreach (BereavementContact::CADENCE as $spec) {
                $contacts[] = BereavementContact::create([
                    'tenant_id'            => $p->tenant_id,
                    'participant_id'       => $p->id,
                    'contact_type'         => $spec['type'],
                    'family_contact_name'  => $familyContactName,
                    'family_contact_phone' => $familyContactPhone,
                    'scheduled_at'         => $dateOfDeath->copy()->addDays($spec['days']),
                    'status'               => 'scheduled',
                ]);
            }

            AuditLog::record(
                action: 'hospice.death_recorded',
                tenantId: $p->tenant_id,
                userId: $user->id,
                resourceType: 'participant',
                resourceId: $p->id,
                description: "Death recorded {$dateOfDeath->toDateString()} for participant #{$p->id}. Disenrolled + 3 bereavement contacts scheduled.",
            );

            return ['participant' => $p->fresh(), 'bereavement_contacts' => $contacts];
        });
    }
}
