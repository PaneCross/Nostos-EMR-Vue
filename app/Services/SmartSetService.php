<?php

// ─── SmartSetService ─────────────────────────────────────────────────────────
// Phase F2 : quick-order SmartSets. Pre-built bundles of ClinicalOrder rows
// for common admission / exacerbation workflows.
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Services;

use App\Models\AuditLog;
use App\Models\ClinicalOrder;
use App\Models\Participant;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class SmartSetService
{
    public const BUNDLES = [
        'diabetic_admission' => [
            'label' => 'Diabetic admission',
            'orders' => [
                ['order_type' => 'lab',    'instructions' => 'A1C', 'clinical_indication' => 'Diabetes admission protocol'],
                ['order_type' => 'lab',    'instructions' => 'BMP (glucose, creatinine)', 'clinical_indication' => 'Diabetes admission protocol'],
                ['order_type' => 'consult','instructions' => 'Dietary referral : diabetic diet + education', 'clinical_indication' => 'Diabetes admission protocol'],
                ['order_type' => 'medication_change', 'instructions' => 'Fingerstick BG AC+HS', 'clinical_indication' => 'Diabetes admission protocol'],
            ],
        ],
        'chf_exacerbation' => [
            'label' => 'CHF exacerbation',
            'orders' => [
                ['order_type' => 'lab',     'instructions' => 'BNP', 'clinical_indication' => 'CHF exacerbation'],
                ['order_type' => 'lab',     'instructions' => 'BMP + magnesium', 'clinical_indication' => 'CHF exacerbation'],
                ['order_type' => 'imaging', 'instructions' => 'CXR PA/Lat', 'clinical_indication' => 'CHF exacerbation'],
                ['order_type' => 'medication_change', 'instructions' => 'Daily weights + strict I&O + fluid restriction 1.5L', 'clinical_indication' => 'CHF exacerbation'],
            ],
        ],
        'pneumonia' => [
            'label' => 'Pneumonia',
            'orders' => [
                ['order_type' => 'lab',     'instructions' => 'CBC with diff', 'clinical_indication' => 'Pneumonia'],
                ['order_type' => 'lab',     'instructions' => 'Sputum culture + gram stain', 'clinical_indication' => 'Pneumonia'],
                ['order_type' => 'imaging', 'instructions' => 'CXR PA/Lat', 'clinical_indication' => 'Pneumonia'],
                ['order_type' => 'medication_change', 'instructions' => 'Empiric antibiotic per local antibiogram', 'clinical_indication' => 'Pneumonia'],
            ],
        ],
        'uti' => [
            'label' => 'UTI',
            'orders' => [
                ['order_type' => 'lab', 'instructions' => 'Urinalysis with reflex C&S', 'clinical_indication' => 'Suspected UTI'],
                ['order_type' => 'medication_change', 'instructions' => 'Empiric antibiotic per local antibiogram; adjust by C&S', 'clinical_indication' => 'Suspected UTI'],
                ['order_type' => 'consult', 'instructions' => 'Ensure hydration + monitor mental status', 'clinical_indication' => 'Suspected UTI'],
            ],
        ],
    ];

    public function apply(Participant $p, User $user, string $bundleKey): array
    {
        abort_unless(isset(self::BUNDLES[$bundleKey]), 422, 'Unknown SmartSet bundle.');
        $bundle = self::BUNDLES[$bundleKey];

        return DB::transaction(function () use ($p, $user, $bundleKey, $bundle) {
            $created = [];
            foreach ($bundle['orders'] as $spec) {
                $created[] = ClinicalOrder::create([
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
                action: 'smartset.applied',
                tenantId: $p->tenant_id,
                userId: $user->id,
                resourceType: 'participant',
                resourceId: $p->id,
                description: "SmartSet '{$bundle['label']}' applied: " . count($created) . ' orders created.',
            );

            return $created;
        });
    }
}
