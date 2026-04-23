<?php

namespace Database\Seeders;

use App\Models\DrugLabInteraction;
use Illuminate\Database\Seeder;

/**
 * Phase B5 — Drug-lab interaction reference data.
 *
 * Seeds ~20 common monitoring pairs. References:
 *   - AHFS Drug Monographs
 *   - USPSTF Screening Recommendations
 *   - common PACE formulary patterns
 *
 * Idempotent via upsert on (drug_keyword, lab_name).
 */
class DrugLabInteractionSeeder extends Seeder
{
    public function run(): void
    {
        $rows = [
            // Anticoagulants
            ['drug_keyword' => 'warfarin',    'lab_name' => 'INR',               'loinc_code' => '6301-6',  'monitoring_frequency_days' => 30,  'critical_low' => 1.0,  'critical_high' => 5.0,  'units' => null,    'notes' => 'Target typically 2.0-3.0 AF or 2.5-3.5 mechanical valve.'],
            ['drug_keyword' => 'enoxaparin',  'lab_name' => 'Anti-Xa level',     'loinc_code' => null,      'monitoring_frequency_days' => 90,  'critical_low' => 0.2,  'critical_high' => 1.5,  'units' => 'IU/mL', 'notes' => 'Only in renal impairment or extremes of weight.'],
            ['drug_keyword' => 'heparin',     'lab_name' => 'aPTT',              'loinc_code' => '3173-2',  'monitoring_frequency_days' => 1,   'critical_low' => 30,   'critical_high' => 120,  'units' => 's',     'notes' => 'Continuous infusion — q6h initially.'],

            // Mood stabilizers
            ['drug_keyword' => 'lithium',     'lab_name' => 'Lithium Level',     'loinc_code' => '3719-2',  'monitoring_frequency_days' => 180, 'critical_low' => 0.4,  'critical_high' => 1.5,  'units' => 'mEq/L', 'notes' => 'Toxicity >1.5. Check SCr + TSH + Ca twice yearly.'],
            ['drug_keyword' => 'lithium',     'lab_name' => 'Creatinine',        'loinc_code' => '2160-0',  'monitoring_frequency_days' => 180, 'critical_low' => null, 'critical_high' => 1.5,  'units' => 'mg/dL', 'notes' => null],
            ['drug_keyword' => 'lithium',     'lab_name' => 'TSH',               'loinc_code' => '3016-3',  'monitoring_frequency_days' => 365, 'critical_low' => null, 'critical_high' => null, 'units' => 'mIU/L', 'notes' => null],

            // Cardiac
            ['drug_keyword' => 'digoxin',     'lab_name' => 'Digoxin Level',     'loinc_code' => '10535-3', 'monitoring_frequency_days' => 180, 'critical_low' => 0.5,  'critical_high' => 2.0,  'units' => 'ng/mL', 'notes' => 'Toxicity >2.0. Also monitor K+/Mg+.'],
            ['drug_keyword' => 'digoxin',     'lab_name' => 'Potassium',         'loinc_code' => '2823-3',  'monitoring_frequency_days' => 90,  'critical_low' => 3.5,  'critical_high' => 5.0,  'units' => 'mEq/L', 'notes' => 'Hypokalemia potentiates digoxin toxicity.'],
            ['drug_keyword' => 'amiodarone',  'lab_name' => 'TSH',               'loinc_code' => '3016-3',  'monitoring_frequency_days' => 180, 'critical_low' => null, 'critical_high' => null, 'units' => 'mIU/L', 'notes' => 'Also LFTs + PFTs annually.'],
            ['drug_keyword' => 'amiodarone',  'lab_name' => 'LFT',               'loinc_code' => null,      'monitoring_frequency_days' => 180, 'critical_low' => null, 'critical_high' => null, 'units' => null,    'notes' => null],

            // Anticonvulsants
            ['drug_keyword' => 'phenytoin',   'lab_name' => 'Phenytoin Level',   'loinc_code' => null,      'monitoring_frequency_days' => 90,  'critical_low' => 10,   'critical_high' => 20,   'units' => 'mcg/mL','notes' => 'Free level if albumin low.'],
            ['drug_keyword' => 'valproic',    'lab_name' => 'Valproate Level',   'loinc_code' => null,      'monitoring_frequency_days' => 180, 'critical_low' => 50,   'critical_high' => 100,  'units' => 'mcg/mL','notes' => 'Also LFTs + CBC.'],

            // Immunomodulators
            ['drug_keyword' => 'methotrexate','lab_name' => 'CBC',               'loinc_code' => null,      'monitoring_frequency_days' => 90,  'critical_low' => null, 'critical_high' => null, 'units' => null,    'notes' => 'Also LFTs + creatinine.'],
            ['drug_keyword' => 'methotrexate','lab_name' => 'LFT',               'loinc_code' => null,      'monitoring_frequency_days' => 90,  'critical_low' => null, 'critical_high' => null, 'units' => null,    'notes' => null],

            // Statins
            ['drug_keyword' => 'atorvastatin','lab_name' => 'LFT',               'loinc_code' => null,      'monitoring_frequency_days' => 365, 'critical_low' => null, 'critical_high' => null, 'units' => null,    'notes' => 'Symptom-driven after initial baseline.'],
            ['drug_keyword' => 'simvastatin', 'lab_name' => 'LFT',               'loinc_code' => null,      'monitoring_frequency_days' => 365, 'critical_low' => null, 'critical_high' => null, 'units' => null,    'notes' => null],

            // Diabetes
            ['drug_keyword' => 'metformin',   'lab_name' => 'Creatinine',        'loinc_code' => '2160-0',  'monitoring_frequency_days' => 365, 'critical_low' => null, 'critical_high' => 1.5,  'units' => 'mg/dL', 'notes' => 'Hold if eGFR <30.'],
            ['drug_keyword' => 'insulin',     'lab_name' => 'A1C',               'loinc_code' => '4548-4',  'monitoring_frequency_days' => 90,  'critical_low' => null, 'critical_high' => null, 'units' => '%',     'notes' => null],

            // ACE/ARB
            ['drug_keyword' => 'lisinopril',  'lab_name' => 'Potassium',         'loinc_code' => '2823-3',  'monitoring_frequency_days' => 180, 'critical_low' => 3.5,  'critical_high' => 5.5,  'units' => 'mEq/L', 'notes' => 'Check within 1-2 weeks of initiation.'],
            ['drug_keyword' => 'spironolactone','lab_name' => 'Potassium',       'loinc_code' => '2823-3',  'monitoring_frequency_days' => 90,  'critical_low' => 3.5,  'critical_high' => 5.5,  'units' => 'mEq/L', 'notes' => null],
        ];

        foreach ($rows as $r) {
            DrugLabInteraction::updateOrCreate(
                ['drug_keyword' => $r['drug_keyword'], 'lab_name' => $r['lab_name']],
                $r,
            );
        }

        $this->command?->info('    Drug-lab interaction reference seeded (' . count($rows) . ' rows).');
    }
}
