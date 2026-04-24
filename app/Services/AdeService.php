<?php

// ─── AdeService ──────────────────────────────────────────────────────────────
// Phase C5. Records Adverse Drug Events; on severity >= severe auto-creates
// an Allergy row for the offending drug so the drug-interaction engine
// prevents re-administration.
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Services;

use App\Models\AdverseDrugEvent;
use App\Models\Allergy;
use App\Models\AuditLog;
use App\Models\Medication;
use App\Models\Participant;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class AdeService
{
    public function record(
        Participant $p,
        User $user,
        array $data,
    ): AdverseDrugEvent {
        return DB::transaction(function () use ($p, $user, $data) {
            $medication = isset($data['medication_id'])
                ? Medication::find($data['medication_id'])
                : null;

            $autoAllergy = false;
            if (
                in_array($data['severity'], AdverseDrugEvent::AUTO_ALLERGY_SEVERITIES, true)
                && $medication
            ) {
                // Don't duplicate an existing active allergy for the same drug name.
                $exists = Allergy::where('participant_id', $p->id)
                    ->where('allergen_name', $medication->drug_name)
                    ->where('is_active', true)->exists();
                if (! $exists) {
                    Allergy::create([
                        'tenant_id'            => $p->tenant_id,
                        'participant_id'       => $p->id,
                        'allergy_type'         => 'drug',
                        'allergen_name'        => $medication->drug_name,
                        'rxnorm_code'          => $medication->rxnorm_code,
                        'reaction_description' => $data['reaction_description'],
                        'severity'             => $data['severity'] === 'life_threatening' ? 'severe' : $data['severity'],
                        'onset_date'           => $data['onset_date'],
                        'is_active'            => true,
                        'notes'                => '[auto-added by ADE #] see ADE record for full context',
                    ]);
                    $autoAllergy = true;
                }
            }

            $ade = AdverseDrugEvent::create(array_merge($data, [
                'tenant_id'            => $p->tenant_id,
                'participant_id'       => $p->id,
                'reporter_user_id'     => $user->id,
                'auto_allergy_created' => $autoAllergy,
            ]));

            AuditLog::record(
                action: 'ade.recorded',
                tenantId: $p->tenant_id,
                userId: $user->id,
                resourceType: 'adverse_drug_event',
                resourceId: $ade->id,
                description: "ADE recorded: severity={$ade->severity}, causality={$ade->causality}"
                    . ($autoAllergy ? ' [auto-allergy created]' : ''),
            );

            return $ade;
        });
    }

    public function markReported(AdverseDrugEvent $ade, User $user, string $trackingNumber): AdverseDrugEvent
    {
        $ade->update([
            'reported_to_medwatch_at'   => now(),
            'medwatch_tracking_number'  => $trackingNumber,
        ]);
        AuditLog::record(
            action: 'ade.medwatch_reported',
            tenantId: $ade->tenant_id,
            userId: $user->id,
            resourceType: 'adverse_drug_event',
            resourceId: $ade->id,
            description: "MedWatch report submitted (tracking {$trackingNumber}).",
        );
        return $ade->fresh();
    }
}
