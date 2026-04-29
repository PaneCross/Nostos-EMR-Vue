<?php

// ─── CmsCredentialBaselineSeeder ─────────────────────────────────────────────
// Seeds the 8 CMS-mandatory credential definitions per tenant. These rows are
// flagged is_cms_mandatory=true and cannot be deleted or disabled at the org
// level (or per-site). Executive may still EDIT title/cadence/description.
//
// Sources:
//   - 42 CFR §460.71      : staff training requirements
//   - 42 CFR §460.64-66   : licensed professional credentialing + PSV
//   - 42 CFR §460.74      : infection control / safety training
//   - HIPAA 45 CFR §164.530(b) : annual privacy + security awareness training
//   - OSHA 29 CFR §1910   : annual fire safety, BBP, hazcom training
//
// Targeting follows OR semantics : a user is required to have a credential if
// any of (their dept, their job_title, any of their designations) matches a
// target row for that definition.
// ─────────────────────────────────────────────────────────────────────────────

namespace Database\Seeders;

use App\Models\CredentialDefinition;
use App\Models\CredentialDefinitionTarget;
use App\Models\Tenant;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class CmsCredentialBaselineSeeder extends Seeder
{
    /**
     * The 8 mandatory baseline definitions.
     *
     * targets[].kind ∈ {'department', 'job_title', 'designation', 'all_clinical'}.
     * 'all_clinical' is expanded by this seeder into the relevant departments.
     */
    public const BASELINE = [
        [
            'code'  => 'hipaa_annual_training',
            'title' => 'HIPAA Annual Privacy + Security Training',
            'credential_type' => 'training',
            'description' => '45 CFR §164.530(b). All workforce members must complete annual HIPAA privacy + security awareness training.',
            'requires_psv' => false,
            'default_doc_required' => true,
            'reminder_cadence_days' => [60, 30, 14, 0],
            'sort_order' => 10,
            'targets' => [['kind' => 'all_workforce', 'value' => null]],
        ],
        [
            'code'  => 'fire_safety_annual',
            'title' => 'Fire Safety / Emergency Preparedness Training',
            'credential_type' => 'training',
            'description' => '42 CFR §460.74 + OSHA. Annual fire safety, evacuation procedures, and emergency preparedness drill participation.',
            'requires_psv' => false,
            'default_doc_required' => true,
            'reminder_cadence_days' => [60, 30, 14, 0],
            'sort_order' => 20,
            'targets' => [['kind' => 'all_workforce', 'value' => null]],
        ],
        [
            'code'  => 'infection_control_annual',
            'title' => 'Infection Control / Bloodborne Pathogens Training',
            'credential_type' => 'training',
            'description' => '42 CFR §460.74(c) + OSHA 29 CFR §1910.1030. Annual infection control + BBP exposure plan training.',
            'requires_psv' => false,
            'default_doc_required' => true,
            'reminder_cadence_days' => [60, 30, 14, 0],
            'sort_order' => 30,
            'targets' => [['kind' => 'all_workforce', 'value' => null]],
        ],
        [
            'code'  => 'abuse_neglect_reporting',
            'title' => 'Abuse / Neglect / Exploitation Reporting Training',
            'credential_type' => 'training',
            'description' => '42 CFR §460.71(a)(3). Annual training on identifying and reporting abuse, neglect, and exploitation of participants.',
            'requires_psv' => false,
            'default_doc_required' => true,
            'reminder_cadence_days' => [60, 30, 14, 0],
            'sort_order' => 40,
            'targets' => [['kind' => 'all_workforce', 'value' => null]],
        ],
        [
            'code'  => 'restraint_training',
            'title' => 'Restraint / Behavioral Health Crisis Training',
            'credential_type' => 'training',
            'description' => '42 CFR §460.114. Required for clinical staff who may apply or supervise restraints. Annual refresh.',
            'requires_psv' => false,
            'default_doc_required' => true,
            'reminder_cadence_days' => [60, 30, 14, 0],
            'sort_order' => 50,
            'targets' => [['kind' => 'all_clinical', 'value' => null]],
        ],
        [
            'code'  => 'tb_clearance',
            'title' => 'TB Clearance (Initial 2-step + Annual Symptom Screen)',
            'credential_type' => 'tb_clearance',
            'description' => '42 CFR §460.71(b)(2). Initial 2-step TB skin test or IGRA, then annual symptom screen. Document upload required.',
            'requires_psv' => false,
            'default_doc_required' => true,
            'reminder_cadence_days' => [60, 30, 14, 0],
            'sort_order' => 60,
            'targets' => [['kind' => 'all_workforce', 'value' => null]],
        ],
        [
            'code'  => 'background_check',
            'title' => 'Criminal Background Check',
            'credential_type' => 'background_check',
            'description' => '42 CFR §460.71(b)(3). Required at hire and per state-mandated re-check intervals (typically every 2 years).',
            'requires_psv' => true,
            'default_doc_required' => true,
            'reminder_cadence_days' => [90, 30, 0],
            'sort_order' => 70,
            'targets' => [['kind' => 'all_workforce', 'value' => null]],
        ],
        [
            'code'  => 'bls_certification',
            'title' => 'BLS / CPR Certification',
            'credential_type' => 'certification',
            'description' => 'AHA-approved Basic Life Support certification. Required for all clinical staff who may respond to participant emergencies. Renews every 2 years.',
            'requires_psv' => false,
            'default_doc_required' => true,
            'reminder_cadence_days' => [60, 30, 14, 0],
            'sort_order' => 80,
            'targets' => [['kind' => 'all_clinical', 'value' => null]],
        ],
        // C4 : New-hire 8-hour PACE orientation per §460.71(b)(2). One-time
        // event that must be completed within 30 days of hire. Modeled with
        // far-future expires_at conceptually (lifetime credential).
        [
            'code'  => 'pace_orientation_8h',
            'title' => 'PACE Orientation (8-hour, within 30 days of hire)',
            'credential_type' => 'training',
            'description' => '42 CFR §460.71(b)(2). 8-hour PACE-specific orientation completed within the first 30 days of employment. One-time event ; document upload required.',
            'requires_psv' => false,
            'default_doc_required' => true,
            'reminder_cadence_days' => [30, 14, 0],
            'sort_order' => 5,
            'targets' => [['kind' => 'all_workforce', 'value' => null]],
        ],
        // C3 : OIG LEIE / GSA SAM exclusion check. CMS COP requires monthly
        // verification that no staff are on the federal exclusion lists.
        [
            'code'  => 'oig_sam_exclusion_check',
            'title' => 'OIG LEIE + GSA SAM Exclusion Check',
            'credential_type' => 'background_check',
            'description' => 'CMS Conditions of Participation : verify staff are not on the OIG List of Excluded Individuals/Entities (LEIE) or GSA SAM excluded-parties list. Run monthly ; document upload required (screenshot of the negative search result).',
            'requires_psv' => true,
            'default_doc_required' => true,
            'reminder_cadence_days' => [7, 0, -7],
            'sort_order' => 75,
            'targets' => [['kind' => 'all_workforce', 'value' => null]],
        ],
        // C1 : Annual competency evaluation per §460.71(b)(1). Each clinical
        // role gets an annual eval signed by their supervisor.
        [
            'code'  => 'annual_competency_evaluation',
            'title' => 'Annual Competency Evaluation',
            'credential_type' => 'competency',
            'description' => '42 CFR §460.71(b)(1). Supervisor-signed annual competency evaluation appropriate to the staff member\'s role. Document upload required (signed eval form / observed-skills checklist).',
            'requires_psv' => false,
            'default_doc_required' => true,
            'reminder_cadence_days' => [60, 30, 14, 0],
            'sort_order' => 90,
            'targets' => [['kind' => 'all_clinical', 'value' => null]],
        ],
        // C2 : Supervising-physician agreement for NPs + PAs per §460.103.
        [
            'code'  => 'supervising_physician_agreement',
            'title' => 'Supervising Physician Agreement (NP / PA)',
            'credential_type' => 'other',
            'description' => '42 CFR §460.103. Written supervising-physician agreement on file. Required for every NP and PA per state scope-of-practice law. Annual review.',
            'requires_psv' => false,
            'default_doc_required' => true,
            'reminder_cadence_days' => [90, 30, 14, 0],
            'sort_order' => 95,
            'targets' => [
                ['kind' => 'job_title', 'value' => 'np'],
                ['kind' => 'job_title', 'value' => 'pa'],
            ],
        ],
    ];

    /** Departments considered "all clinical" for restraint + BLS targeting. */
    public const CLINICAL_DEPARTMENTS = [
        'primary_care',
        'therapies',
        'social_work',
        'behavioral_health',
        'home_care',
        'pharmacy',
        'idt',
    ];

    /** Departments considered "all workforce" : every staff dept. */
    public const ALL_WORKFORCE_DEPARTMENTS = [
        'primary_care', 'therapies', 'social_work', 'behavioral_health',
        'dietary', 'activities', 'home_care', 'transportation',
        'pharmacy', 'idt', 'enrollment', 'finance', 'qa_compliance', 'it_admin',
        'executive',
    ];

    public function run(): void
    {
        // B7 : ensure NotificationPreferenceService default rows are seeded
        // for each tenant so the Org Settings → Notifications tab shows
        // every credential pref (and every other catalog key) with a
        // persisted default rather than a lazy fallback.
        $prefService = app(\App\Services\NotificationPreferenceService::class);

        Tenant::all()->each(function (Tenant $tenant) use ($prefService) {
            $prefService->seedDefaults($tenant->id);

            DB::transaction(function () use ($tenant) {
                foreach (self::BASELINE as $defData) {
                    $def = CredentialDefinition::updateOrCreate(
                        [
                            'tenant_id' => $tenant->id,
                            'site_id'   => null,
                            'code'      => $defData['code'],
                        ],
                        [
                            'title'                  => $defData['title'],
                            'credential_type'        => $defData['credential_type'],
                            'description'            => $defData['description'],
                            'requires_psv'           => $defData['requires_psv'],
                            'is_cms_mandatory'       => true,
                            'default_doc_required'   => $defData['default_doc_required'],
                            'reminder_cadence_days'  => $defData['reminder_cadence_days'],
                            'is_active'              => true,
                            'sort_order'             => $defData['sort_order'],
                        ]
                    );

                    // Wipe + re-create targets so seeder is fully idempotent.
                    CredentialDefinitionTarget::where('credential_definition_id', $def->id)->delete();

                    foreach ($defData['targets'] as $t) {
                        $rows = $this->expandTarget($t);
                        foreach ($rows as $row) {
                            CredentialDefinitionTarget::create([
                                'credential_definition_id' => $def->id,
                                'target_kind'              => $row['kind'],
                                'target_value'             => $row['value'],
                            ]);
                        }
                    }
                }
            });
        });
    }

    private function expandTarget(array $t): array
    {
        if ($t['kind'] === 'all_workforce') {
            return array_map(
                fn ($d) => ['kind' => 'department', 'value' => $d],
                self::ALL_WORKFORCE_DEPARTMENTS
            );
        }
        if ($t['kind'] === 'all_clinical') {
            return array_map(
                fn ($d) => ['kind' => 'department', 'value' => $d],
                self::CLINICAL_DEPARTMENTS
            );
        }
        return [['kind' => $t['kind'], 'value' => $t['value']]];
    }
}
