<?php

// ─── RoleSpecificCredentialSeeder ────────────────────────────────────────────
// Adds the per-role credential definitions that PACE orgs typically track but
// that aren't seeded by CmsCredentialBaselineSeeder (which only handles the
// 8 workforce-wide federal mandates).
//
// These rows are NOT marked is_cms_mandatory, so the executive can disable
// per-site or remove org-wide if their state/program doesn't track them.
// They ARE seeded with reasonable PSV + cadence defaults based on industry
// norms.
//
// Targeting uses job_title (not department) since most are licensure that
// only certain roles within a department hold (an RN License only applies
// to job_title=rn, even though "primary_care" department also has MAs and
// physicians).
//
// Sources:
//   - State Boards of Nursing (RN/LPN renewal cycles vary by state, 2y typical)
//   - DEA 21 CFR §1301.13 (3-year registration cycle)
//   - AHA BLS / ACLS (2-year cycle)
//   - FMCSA 49 CFR §391 (DOT med card 2y, MVR per state policy ~3y)
//   - NBCOT, FSBPT, CDR (varies by board)
// ─────────────────────────────────────────────────────────────────────────────

namespace Database\Seeders;

use App\Models\CredentialDefinition;
use App\Models\CredentialDefinitionTarget;
use App\Models\Tenant;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class RoleSpecificCredentialSeeder extends Seeder
{
    public const DEFINITIONS = [
        // ── Nursing licenses ─────────────────────────────────────────────────
        [
            'code'                  => 'rn_license',
            'title'                 => 'RN License',
            'credential_type'       => 'license',
            'description'           => 'State Board of Nursing RN license. Primary-source verify via state board lookup. Typically 2-year renewal cycle (varies by state). Most states require ~30 CEU hours per cycle.',
            'requires_psv'          => true,
            'default_doc_required'  => true,
            'reminder_cadence_days' => [90, 60, 30, 14, 0],
            'ceu_hours_required'    => 30,
            'sort_order'            => 100,
            'targets'               => [['kind' => 'job_title', 'value' => 'rn']],
        ],
        [
            'code'                  => 'lpn_license',
            'title'                 => 'LPN / LVN License',
            'credential_type'       => 'license',
            'description'           => 'State Board of Nursing LPN/LVN license. PSV via state board.',
            'requires_psv'          => true,
            'default_doc_required'  => true,
            'reminder_cadence_days' => [90, 60, 30, 14, 0],
            'ceu_hours_required'    => 24,
            'sort_order'            => 101,
            'targets'               => [['kind' => 'job_title', 'value' => 'lpn']],
        ],
        [
            'code'                  => 'cna_certification',
            'title'                 => 'CNA Certification',
            'credential_type'       => 'certification',
            'description'           => 'Certified Nursing Assistant state cert. Renewal typically every 2 years with 12+ hours of in-service training.',
            'requires_psv'          => true,
            'default_doc_required'  => true,
            'reminder_cadence_days' => [60, 30, 14, 0],
            'sort_order'            => 102,
            'targets'               => [['kind' => 'job_title', 'value' => 'cna']],
        ],

        // ── Provider licenses + DEA ─────────────────────────────────────────
        [
            'code'                  => 'md_do_license',
            'title'                 => 'MD / DO License',
            'credential_type'       => 'license',
            'description'           => 'State Medical Board license. PSV via state board + NPDB query at hire and on credentialing review.',
            'requires_psv'          => true,
            'default_doc_required'  => true,
            'reminder_cadence_days' => [120, 90, 60, 30, 14, 0],
            'ceu_hours_required'    => 50,
            'sort_order'            => 110,
            'targets'               => [['kind' => 'job_title', 'value' => 'md']],
        ],
        [
            'code'                  => 'np_license',
            'title'                 => 'NP License + Furnishing Number',
            'credential_type'       => 'license',
            'description'           => 'State Board of Nursing advanced-practice + state furnishing license where applicable.',
            'requires_psv'          => true,
            'default_doc_required'  => true,
            'reminder_cadence_days' => [120, 90, 60, 30, 14, 0],
            'ceu_hours_required'    => 30,
            'sort_order'            => 111,
            'targets'               => [['kind' => 'job_title', 'value' => 'np']],
        ],
        [
            'code'                  => 'pa_license',
            'title'                 => 'PA License',
            'credential_type'       => 'license',
            'description'           => 'State Physician Assistant license + supervising physician agreement on file.',
            'requires_psv'          => true,
            'default_doc_required'  => true,
            'reminder_cadence_days' => [120, 90, 60, 30, 14, 0],
            'ceu_hours_required'    => 30,
            'sort_order'            => 112,
            'targets'               => [['kind' => 'job_title', 'value' => 'pa']],
        ],
        [
            'code'                  => 'dea_registration',
            'title'                 => 'DEA Registration (Schedule II-V)',
            'credential_type'       => 'license',
            'description'           => 'DEA Schedule II-V prescribing registration per 21 CFR §1301.13. 3-year renewal cycle. PSV via DEA verification.',
            'requires_psv'          => true,
            'default_doc_required'  => true,
            'reminder_cadence_days' => [180, 90, 30, 0],
            'sort_order'            => 115,
            'targets'               => [
                ['kind' => 'job_title', 'value' => 'md'],
                ['kind' => 'job_title', 'value' => 'np'],
                ['kind' => 'job_title', 'value' => 'pa'],
            ],
        ],
        [
            'code'                  => 'acls_certification',
            'title'                 => 'ACLS Certification',
            'credential_type'       => 'certification',
            'description'           => 'AHA Advanced Cardiovascular Life Support. Recommended for clinical leads / IDT physicians. 2-year renewal.',
            'requires_psv'          => false,
            'default_doc_required'  => true,
            'reminder_cadence_days' => [60, 30, 14, 0],
            'sort_order'            => 120,
            'targets'               => [
                ['kind' => 'job_title', 'value' => 'md'],
                ['kind' => 'job_title', 'value' => 'np'],
            ],
        ],

        // ── Therapies ────────────────────────────────────────────────────────
        [
            'code'                  => 'pt_license',
            'title'                 => 'PT License',
            'credential_type'       => 'license',
            'description'           => 'State Board of Physical Therapy license. PSV via state board / FSBPT.',
            'requires_psv'          => true,
            'default_doc_required'  => true,
            'reminder_cadence_days' => [90, 60, 30, 14, 0],
            'ceu_hours_required'    => 30,
            'sort_order'            => 130,
            'targets'               => [['kind' => 'job_title', 'value' => 'pt']],
        ],
        [
            'code'                  => 'ot_license',
            'title'                 => 'OT License + NBCOT',
            'credential_type'       => 'license',
            'description'           => 'State OT license + NBCOT certification. PSV via state board / NBCOT.',
            'requires_psv'          => true,
            'default_doc_required'  => true,
            'reminder_cadence_days' => [90, 60, 30, 14, 0],
            'ceu_hours_required'    => 36,
            'sort_order'            => 131,
            'targets'               => [['kind' => 'job_title', 'value' => 'ot']],
        ],

        // ── Social work + dietary ───────────────────────────────────────────
        [
            'code'                  => 'lcsw_license',
            'title'                 => 'LCSW License',
            'credential_type'       => 'license',
            'description'           => 'State Licensed Clinical Social Worker license. PSV via state board.',
            'requires_psv'          => true,
            'default_doc_required'  => true,
            'reminder_cadence_days' => [90, 60, 30, 14, 0],
            'ceu_hours_required'    => 30,
            'sort_order'            => 140,
            'targets'               => [['kind' => 'job_title', 'value' => 'lcsw']],
        ],
        [
            'code'                  => 'msw_license',
            'title'                 => 'MSW License',
            'credential_type'       => 'license',
            'description'           => 'Master Social Worker state license (where state requires).',
            'requires_psv'          => true,
            'default_doc_required'  => true,
            'reminder_cadence_days' => [90, 60, 30, 14, 0],
            'ceu_hours_required'    => 24,
            'sort_order'            => 141,
            'targets'               => [['kind' => 'job_title', 'value' => 'msw']],
        ],
        [
            'code'                  => 'rd_registration',
            'title'                 => 'RD Registration (CDR)',
            'credential_type'       => 'license',
            'description'           => 'Commission on Dietetic Registration. 5-year renewal cycle with 75 CEU hours.',
            'requires_psv'          => true,
            'default_doc_required'  => true,
            'reminder_cadence_days' => [180, 90, 30, 0],
            'ceu_hours_required'    => 75,
            'sort_order'            => 150,
            'targets'               => [['kind' => 'job_title', 'value' => 'rd']],
        ],

        // ── Pharmacy ─────────────────────────────────────────────────────────
        [
            'code'                  => 'pharmacist_license',
            'title'                 => 'Pharmacist License',
            'credential_type'       => 'license',
            'description'           => 'State Board of Pharmacy license. PSV via state board.',
            'requires_psv'          => true,
            'default_doc_required'  => true,
            'reminder_cadence_days' => [90, 60, 30, 14, 0],
            'ceu_hours_required'    => 30,
            'sort_order'            => 160,
            'targets'               => [['kind' => 'job_title', 'value' => 'pharmacist']],
        ],
        [
            'code'                  => 'pharm_tech_registration',
            'title'                 => 'Pharmacy Technician Registration',
            'credential_type'       => 'license',
            'description'           => 'State pharmacy technician registration / certification (PTCB or state-equivalent).',
            'requires_psv'          => true,
            'default_doc_required'  => true,
            'reminder_cadence_days' => [60, 30, 14, 0],
            'sort_order'            => 161,
            'targets'               => [['kind' => 'job_title', 'value' => 'pharm_tech']],
        ],

        // ── Home care ────────────────────────────────────────────────────────
        [
            'code'                  => 'home_care_aide_cert',
            'title'                 => 'Home Care Aide Certification',
            'credential_type'       => 'certification',
            'description'           => 'State home care aide / HHA certification (varies : CHHA, HHA, PCA depending on state).',
            'requires_psv'          => false,
            'default_doc_required'  => true,
            'reminder_cadence_days' => [60, 30, 14, 0],
            'sort_order'            => 170,
            'targets'               => [['kind' => 'job_title', 'value' => 'home_care_aide']],
        ],

        // ── Transport / drivers ──────────────────────────────────────────────
        [
            'code'                  => 'cdl',
            'title'                 => 'Driver License (CDL or Class B)',
            'credential_type'       => 'driver_record',
            'description'           => 'State driver license : CDL or appropriate class for vehicle operated. PACE drivers transporting more than 15 passengers typically need CDL with passenger endorsement.',
            'requires_psv'          => true,
            'default_doc_required'  => true,
            'reminder_cadence_days' => [90, 30, 0],
            'sort_order'            => 180,
            'targets'               => [['kind' => 'job_title', 'value' => 'driver']],
        ],
        [
            'code'                  => 'dot_medical_card',
            'title'                 => 'DOT Medical Examiner Card',
            'credential_type'       => 'driver_record',
            'description'           => 'FMCSA 49 CFR §391.43 medical exam card. 2-year cycle (1-year if specific health conditions).',
            'requires_psv'          => true,
            'default_doc_required'  => true,
            'reminder_cadence_days' => [90, 30, 14, 0],
            'sort_order'            => 181,
            'targets'               => [['kind' => 'job_title', 'value' => 'driver']],
        ],
        [
            'code'                  => 'mvr_check',
            'title'                 => 'Motor Vehicle Record (MVR) Check',
            'credential_type'       => 'driver_record',
            'description'           => 'Periodic MVR pull for driving record review. Typical cadence is annual to triennial depending on state + insurance carrier policy.',
            'requires_psv'          => true,
            'default_doc_required'  => true,
            'reminder_cadence_days' => [60, 30, 0],
            'sort_order'            => 182,
            'targets'               => [['kind' => 'job_title', 'value' => 'driver']],
        ],
    ];

    public function run(): void
    {
        Tenant::all()->each(function (Tenant $tenant) {
            DB::transaction(function () use ($tenant) {
                foreach (self::DEFINITIONS as $defData) {
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
                            'is_cms_mandatory'       => false, // executive choice
                            'ceu_hours_required'    => $defData['ceu_hours_required'] ?? 0,
                            'default_doc_required'   => $defData['default_doc_required'],
                            'reminder_cadence_days'  => $defData['reminder_cadence_days'],
                            'is_active'              => true,
                            'sort_order'             => $defData['sort_order'],
                        ]
                    );

                    // Wipe + re-create targets (idempotent)
                    CredentialDefinitionTarget::where('credential_definition_id', $def->id)->delete();
                    foreach ($defData['targets'] as $t) {
                        CredentialDefinitionTarget::create([
                            'credential_definition_id' => $def->id,
                            'target_kind'              => $t['kind'],
                            'target_value'             => $t['value'],
                        ]);
                    }
                }
            });
        });
    }
}
