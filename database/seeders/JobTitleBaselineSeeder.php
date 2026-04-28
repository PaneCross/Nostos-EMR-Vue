<?php

// ─── JobTitleBaselineSeeder ──────────────────────────────────────────────────
// Seeds a default job-title vocabulary into every existing tenant. Idempotent :
// uses updateOrCreate keyed on (tenant_id, code). Executive can edit / add /
// deactivate from Org Settings → Job Titles.
//
// Default vocab covers the most common PACE roles. Orgs can add their own.
// ─────────────────────────────────────────────────────────────────────────────

namespace Database\Seeders;

use App\Models\JobTitle;
use App\Models\Tenant;
use Illuminate\Database\Seeder;

class JobTitleBaselineSeeder extends Seeder
{
    public const DEFAULT_TITLES = [
        ['code' => 'rn',                 'label' => 'Registered Nurse (RN)',          'sort_order' => 10],
        ['code' => 'lpn',                'label' => 'Licensed Practical Nurse (LPN)', 'sort_order' => 11],
        ['code' => 'cna',                'label' => 'Certified Nursing Assistant (CNA)','sort_order' => 12],
        ['code' => 'ma',                 'label' => 'Medical Assistant (MA)',         'sort_order' => 13],
        ['code' => 'md',                 'label' => 'Physician (MD/DO)',              'sort_order' => 20],
        ['code' => 'np',                 'label' => 'Nurse Practitioner (NP)',        'sort_order' => 21],
        ['code' => 'pa',                 'label' => 'Physician Assistant (PA)',       'sort_order' => 22],
        ['code' => 'msw',                'label' => 'Master Social Worker (MSW)',     'sort_order' => 30],
        ['code' => 'lcsw',               'label' => 'Licensed Clinical Social Worker (LCSW)', 'sort_order' => 31],
        ['code' => 'ot',                 'label' => 'Occupational Therapist (OT)',    'sort_order' => 40],
        ['code' => 'pt',                 'label' => 'Physical Therapist (PT)',        'sort_order' => 41],
        ['code' => 'rd',                 'label' => 'Registered Dietitian (RD)',      'sort_order' => 42],
        ['code' => 'driver',             'label' => 'Transport Driver',               'sort_order' => 50],
        ['code' => 'scheduler',          'label' => 'Scheduler / Coordinator',        'sort_order' => 51],
        ['code' => 'center_manager',     'label' => 'Center Manager',                 'sort_order' => 60],
        ['code' => 'admin_assistant',    'label' => 'Administrative Assistant',       'sort_order' => 61],
        ['code' => 'pharmacist',         'label' => 'Pharmacist',                     'sort_order' => 70],
        ['code' => 'pharm_tech',         'label' => 'Pharmacy Technician',            'sort_order' => 71],
        ['code' => 'recreation_aide',    'label' => 'Recreation Aide',                'sort_order' => 80],
        ['code' => 'home_care_aide',     'label' => 'Home Care Aide',                 'sort_order' => 81],
        ['code' => 'other',              'label' => 'Other',                          'sort_order' => 999],
    ];

    public function run(): void
    {
        Tenant::all()->each(function (Tenant $tenant) {
            foreach (self::DEFAULT_TITLES as $jt) {
                JobTitle::updateOrCreate(
                    ['tenant_id' => $tenant->id, 'code' => $jt['code']],
                    [
                        'label'      => $jt['label'],
                        'sort_order' => $jt['sort_order'],
                        'is_active'  => true,
                    ]
                );
            }
        });
    }
}
