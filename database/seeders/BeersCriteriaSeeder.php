<?php

namespace Database\Seeders;

use App\Models\BeersCriterion;
use Illuminate\Database\Seeder;

/**
 * Phase C6 — AGS Beers Criteria 2023 (abbreviated subset).
 * Real full Beers list is ~100+ meds; this seeds the ~30 highest-risk /
 * commonly-encountered items for PACE populations. Enough to demonstrate
 * flag behavior + drive pharmacist review surface.
 */
class BeersCriteriaSeeder extends Seeder
{
    public function run(): void
    {
        $rows = [
            // Anticholinergics — fall, cognitive impairment
            ['drug_keyword' => 'diphenhydramine', 'risk_category' => 'anticholinergic (1st-gen antihistamine)', 'rationale' => 'Strong anticholinergic effects; confusion, sedation, falls in older adults.', 'recommendation' => 'avoid', 'evidence_quality' => 'strong'],
            ['drug_keyword' => 'hydroxyzine',     'risk_category' => 'anticholinergic', 'rationale' => 'Strong anticholinergic effects; consider alternatives.', 'recommendation' => 'avoid', 'evidence_quality' => 'strong'],
            ['drug_keyword' => 'amitriptyline',   'risk_category' => 'TCA (anticholinergic)', 'rationale' => 'Highly anticholinergic + sedating + orthostatic hypotension.', 'recommendation' => 'avoid', 'evidence_quality' => 'strong'],
            ['drug_keyword' => 'nortriptyline',   'risk_category' => 'TCA (anticholinergic)', 'rationale' => 'Less anticholinergic than amitriptyline but still caution.', 'recommendation' => 'use_with_caution', 'evidence_quality' => 'moderate'],
            ['drug_keyword' => 'oxybutynin',      'risk_category' => 'anticholinergic (urge incontinence)', 'rationale' => 'Older agent; mirabegron preferred when possible.', 'recommendation' => 'avoid', 'evidence_quality' => 'moderate'],

            // Benzodiazepines — falls, cognitive
            ['drug_keyword' => 'diazepam',   'risk_category' => 'benzodiazepine (long-acting)', 'rationale' => 'Long half-life; accumulation; falls + confusion.', 'recommendation' => 'avoid', 'evidence_quality' => 'strong'],
            ['drug_keyword' => 'lorazepam',  'risk_category' => 'benzodiazepine', 'rationale' => 'All BZDs increase fall + cognitive risk; reserve for severe situations.', 'recommendation' => 'avoid', 'evidence_quality' => 'strong'],
            ['drug_keyword' => 'alprazolam', 'risk_category' => 'benzodiazepine', 'rationale' => 'Same; high addiction potential.', 'recommendation' => 'avoid', 'evidence_quality' => 'strong'],
            ['drug_keyword' => 'clonazepam', 'risk_category' => 'benzodiazepine', 'rationale' => 'Same.', 'recommendation' => 'avoid', 'evidence_quality' => 'strong'],

            // Z-drugs
            ['drug_keyword' => 'zolpidem',  'risk_category' => 'Z-drug', 'rationale' => 'Similar risks to BZDs — falls + cognitive impairment.', 'recommendation' => 'avoid', 'evidence_quality' => 'strong'],
            ['drug_keyword' => 'eszopiclone','risk_category' => 'Z-drug', 'rationale' => 'Same.', 'recommendation' => 'avoid', 'evidence_quality' => 'moderate'],

            // NSAIDs — long-term
            ['drug_keyword' => 'ibuprofen', 'risk_category' => 'NSAID (long-term)', 'rationale' => 'GI bleed + renal decline; avoid long-term unless alternatives failed.', 'recommendation' => 'use_with_caution', 'evidence_quality' => 'strong'],
            ['drug_keyword' => 'naproxen',  'risk_category' => 'NSAID (long-term)', 'rationale' => 'Same.', 'recommendation' => 'use_with_caution', 'evidence_quality' => 'strong'],

            // PPI — long-term
            ['drug_keyword' => 'omeprazole',   'risk_category' => 'PPI long-term', 'rationale' => '>8 weeks risks: C. diff, fractures, B12 deficiency.', 'recommendation' => 'use_with_caution', 'evidence_quality' => 'moderate'],
            ['drug_keyword' => 'pantoprazole', 'risk_category' => 'PPI long-term', 'rationale' => 'Same.', 'recommendation' => 'use_with_caution', 'evidence_quality' => 'moderate'],

            // Skeletal muscle relaxants
            ['drug_keyword' => 'cyclobenzaprine', 'risk_category' => 'muscle relaxant', 'rationale' => 'Anticholinergic + sedating; poor efficacy in older adults.', 'recommendation' => 'avoid', 'evidence_quality' => 'moderate'],
            ['drug_keyword' => 'carisoprodol',    'risk_category' => 'muscle relaxant', 'rationale' => 'Sedating + dependency risk.', 'recommendation' => 'avoid', 'evidence_quality' => 'moderate'],

            // Antipsychotics (dementia behavioral)
            ['drug_keyword' => 'haloperidol',  'risk_category' => 'antipsychotic in dementia', 'rationale' => 'Increased mortality in dementia BPSD; use only if behaviors pose serious risk.', 'recommendation' => 'use_with_caution', 'evidence_quality' => 'strong'],
            ['drug_keyword' => 'risperidone',  'risk_category' => 'antipsychotic in dementia', 'rationale' => 'Same.', 'recommendation' => 'use_with_caution', 'evidence_quality' => 'strong'],
            ['drug_keyword' => 'olanzapine',   'risk_category' => 'antipsychotic in dementia', 'rationale' => 'Same; also metabolic.', 'recommendation' => 'use_with_caution', 'evidence_quality' => 'strong'],
            ['drug_keyword' => 'quetiapine',   'risk_category' => 'antipsychotic in dementia', 'rationale' => 'Same; orthostatic.', 'recommendation' => 'use_with_caution', 'evidence_quality' => 'moderate'],

            // Sulfonylureas
            ['drug_keyword' => 'glyburide',    'risk_category' => 'sulfonylurea (long-acting)', 'rationale' => 'Prolonged hypoglycemia risk; glipizide preferred.', 'recommendation' => 'avoid', 'evidence_quality' => 'strong'],

            // Estrogens (systemic)
            ['drug_keyword' => 'estradiol',    'risk_category' => 'systemic estrogen', 'rationale' => 'Avoid oral/systemic in older women (cancer + thrombosis risk).', 'recommendation' => 'avoid', 'evidence_quality' => 'strong'],

            // Alpha-blockers for HTN
            ['drug_keyword' => 'doxazosin',    'risk_category' => 'alpha-blocker for HTN', 'rationale' => 'Orthostatic hypotension; not first-line HTN in older adults.', 'recommendation' => 'avoid', 'evidence_quality' => 'moderate'],

            // Digoxin > 0.125 mg
            ['drug_keyword' => 'digoxin',      'risk_category' => 'narrow therapeutic index', 'rationale' => 'Doses >0.125 mg/day associated with increased toxicity; monitor level + renal function.', 'recommendation' => 'use_with_caution', 'evidence_quality' => 'strong'],

            // Meperidine
            ['drug_keyword' => 'meperidine',   'risk_category' => 'opioid (neurotoxic metabolite)', 'rationale' => 'Normeperidine accumulates; confusion + seizures; avoid.', 'recommendation' => 'avoid', 'evidence_quality' => 'strong'],

            // Metoclopramide
            ['drug_keyword' => 'metoclopramide','risk_category' => 'extrapyramidal risk', 'rationale' => 'EPS + tardive dyskinesia; avoid >12 weeks.', 'recommendation' => 'use_with_caution', 'evidence_quality' => 'moderate'],

            // Chlorpromazine / older antipsychotics
            ['drug_keyword' => 'chlorpromazine','risk_category' => 'first-gen antipsychotic', 'rationale' => 'Strong anticholinergic + orthostatic.', 'recommendation' => 'avoid', 'evidence_quality' => 'strong'],
        ];

        foreach ($rows as $r) {
            BeersCriterion::updateOrCreate(
                ['drug_keyword' => $r['drug_keyword']],
                $r,
            );
        }

        $this->command?->info('    Beers Criteria reference seeded (' . count($rows) . ' rows).');
    }
}
