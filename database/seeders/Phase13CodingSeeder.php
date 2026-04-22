<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Phase 13.1 — SNOMED CT + RxNorm lookup data for PACE participants.
 *
 * Small, hand-curated slice of common PACE-relevant codes. NOT a full
 * distribution (UMLS licensing + multi-GB size make that untenable for a
 * free-demo EMR). Activation-day note for a paying client: swap this seeder
 * for a full RxNav + SNOMED subset after obtaining a UMLS license.
 */
class Phase13CodingSeeder extends Seeder
{
    public function run(): void
    {
        DB::table('shared_snomed_lookup')->truncate();
        DB::table('shared_rxnorm_lookup')->truncate();

        DB::table('shared_snomed_lookup')->insert($this->snomedRows());
        DB::table('shared_rxnorm_lookup')->insert($this->rxnormRows());

        $this->command?->info(
            '    SNOMED: ' . DB::table('shared_snomed_lookup')->count()
            . ' codes. RxNorm: ' . DB::table('shared_rxnorm_lookup')->count() . ' codes.'
        );
    }

    private function snomedRows(): array
    {
        $now = now();
        $rows = [
            // Cardiovascular
            ['38341003',   'Hypertensive disorder, systemic arterial (disorder)',       'disorder', 'I10'],
            ['44054006',   'Diabetes mellitus type 2 (disorder)',                       'disorder', 'E11.9'],
            ['46635009',   'Diabetes mellitus type 1 (disorder)',                       'disorder', 'E10.9'],
            ['53741008',   'Coronary arteriosclerosis (disorder)',                      'disorder', 'I25.10'],
            ['84114007',   'Heart failure (disorder)',                                  'disorder', 'I50.9'],
            ['49436004',   'Atrial fibrillation (disorder)',                            'disorder', 'I48.91'],
            ['22298006',   'Myocardial infarction (disorder)',                          'disorder', 'I21.9'],
            // Cognitive / behavioral
            ['26929004',   'Alzheimer disease (disorder)',                              'disorder', 'G30.9'],
            ['52448006',   'Dementia (disorder)',                                       'disorder', 'F03.90'],
            ['370143000',  'Major depressive disorder (disorder)',                      'disorder', 'F32.9'],
            ['48694002',   'Anxiety disorder (disorder)',                               'disorder', 'F41.9'],
            ['56576003',   'Bipolar disorder (disorder)',                               'disorder', 'F31.9'],
            // Respiratory
            ['13645005',   'Chronic obstructive lung disease (disorder)',               'disorder', 'J44.9'],
            ['195967001',  'Asthma (disorder)',                                         'disorder', 'J45.909'],
            ['233604007',  'Pneumonia (disorder)',                                      'disorder', 'J18.9'],
            // Kidney
            ['709044004',  'Chronic kidney disease (disorder)',                         'disorder', 'N18.9'],
            ['431855005',  'End stage renal disease (disorder)',                        'disorder', 'N18.6'],
            // Stroke / neuro
            ['230690007',  'Cerebrovascular accident (disorder)',                       'disorder', 'I63.9'],
            ['49049000',   'Parkinson disease (disorder)',                              'disorder', 'G20'],
            ['75543006',   'Epilepsy (disorder)',                                       'disorder', 'G40.909'],
            // Frailty / fall / PACE specific
            ['129839007',  'Frailty (finding)',                                         'finding',  'R54'],
            ['371041009',  'Fall (event)',                                              'finding',  'W19.XXXA'],
            ['420400003',  'Recurrent falls (finding)',                                 'finding',  'R29.6'],
            ['267024001',  'Malaise and fatigue (finding)',                             'finding',  'R53.83'],
            ['238131007',  'Overweight (finding)',                                      'finding',  'E66.3'],
            ['162809002',  'Polypharmacy (finding)',                                    'finding',  'Z91.128'],
            // GI / skin / other chronic
            ['235595009',  'Gastro-esophageal reflux disease (disorder)',               'disorder', 'K21.9'],
            ['95635005',   'Pressure ulcer (disorder)',                                 'disorder', 'L89.90'],
            ['161891005',  'Backache (finding)',                                        'finding',  'M54.9'],
            ['396275006',  'Osteoarthritis (disorder)',                                 'disorder', 'M19.90'],
        ];
        return array_map(fn ($r) => [
            'code' => $r[0], 'display' => $r[1], 'category' => $r[2], 'icd10_code' => $r[3],
            'created_at' => $now, 'updated_at' => $now,
        ], $rows);
    }

    private function rxnormRows(): array
    {
        $now = now();
        $rows = [
            // Common PACE meds (cardio / diabetes / anticoagulants)
            ['314076',   'Lisinopril 10 MG Oral Tablet',                         'SCD', false],
            ['197361',   'Amlodipine 5 MG Oral Tablet',                          'SCD', false],
            ['859749',   'Metformin 500 MG Oral Tablet',                         'SCD', false],
            ['860975',   'Metformin 1000 MG Oral Tablet',                        'SCD', false],
            ['310798',   'Furosemide 40 MG Oral Tablet',                         'SCD', false],
            ['314231',   'Metoprolol Tartrate 25 MG Oral Tablet',                'SCD', false],
            ['308136',   'Atorvastatin 20 MG Oral Tablet',                       'SCD', false],
            ['198211',   'Warfarin Sodium 5 MG Oral Tablet',                     'SCD', false],
            ['855332',   'Apixaban 5 MG Oral Tablet',                            'SCD', false],
            // Common analgesics / neuropsych
            ['198440',   'Acetaminophen 325 MG Oral Tablet',                     'SCD', false],
            ['313782',   'Gabapentin 300 MG Oral Capsule',                       'SCD', false],
            ['310429',   'Donepezil 10 MG Oral Tablet',                          'SCD', false],
            ['313820',   'Sertraline 50 MG Oral Tablet',                         'SCD', true],   // SSRI allergen candidate
            ['197319',   'Alprazolam 0.5 MG Oral Tablet',                        'SCD', false],
            // Ingredients used for drug allergies
            ['1191',     'Aspirin',                                              'IN',  true],
            ['6845',     'Morphine',                                             'IN',  true],
            ['3640',     'Codeine',                                              'IN',  true],
            ['7258',     'Penicillin',                                           'IN',  true],
            ['2191',     'Amoxicillin',                                          'IN',  true],
            ['723',      'Ampicillin',                                           'IN',  true],
            ['2551',     'Sulfamethoxazole',                                     'IN',  true],
            ['2670',     'Ciprofloxacin',                                        'IN',  true],
            ['10180',    'Vancomycin',                                           'IN',  true],
            ['7646',     'Naproxen',                                             'IN',  true],
            ['5640',     'Ibuprofen',                                            'IN',  true],
            ['5489',     'Heparin',                                              'IN',  true],
            ['4337',     'Furosemide',                                           'IN',  false],
            ['6809',     'Metformin',                                            'IN',  false],
            ['29046',    'Lisinopril',                                           'IN',  false],
            ['17767',    'Amlodipine',                                           'IN',  false],
        ];
        return array_map(fn ($r) => [
            'code' => $r[0], 'display' => $r[1], 'tty' => $r[2],
            'is_allergen_candidate' => $r[3],
            'created_at' => $now, 'updated_at' => $now,
        ], $rows);
    }
}
