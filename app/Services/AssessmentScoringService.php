<?php

// ─── AssessmentScoringService ────────────────────────────────────────────────
// Phase 13.2 (MVP roadmap). Structured + scored assessment instruments for
// the existing Assessment model. Rather than create a new table / new model
// per instrument, this service provides:
//
//   - A definition() method returning the instrument's questions + answer
//     options + score-weights. UI calls this to render the questionnaire.
//   - A score() method that takes a participant's answer map + returns the
//     total score + interpretation band + category. Called on save.
//
// Four instruments implemented (MVP set):
//   - PHQ-9 (depression, 9 items, 0-27)
//   - Mini-Cog (cognitive screen, 3 items, 0-5) — license-free
//     alternative to MoCA
//   - Morse Fall Scale (6 items, 0-125)
//   - Katz ADL (6 items, 0-6)
//
// The existing emr_assessments table stores responses as JSONB — answers go
// in `responses`, scoring outputs go in the same row's `total_score` +
// `interpretation` columns if present, else inside `responses['_score']`.
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Services;

class AssessmentScoringService
{
    public const INSTRUMENTS = [
        'phq9_depression',
        'mini_cog',
        'fall_risk_morse',
        'katz_adl',
        // Phase C2b — substance-use screening
        'audit_c_alcohol',
        'cage_alcohol',
        'dast10_substance',
    ];

    /**
     * Phase C2b — Care-plan referral suggestions on positive screens.
     * Map of (instrument, band) → referral hint.
     */
    public const REFERRAL_SUGGESTIONS = [
        'audit_c_alcohol' => [
            'positive' => ['dept' => 'behavioral_health', 'goal' => 'Behavioral-health referral for alcohol-use evaluation (AUDIT-C positive)'],
        ],
        'cage_alcohol' => [
            'positive' => ['dept' => 'behavioral_health', 'goal' => 'Behavioral-health referral for alcohol-use evaluation (CAGE ≥2)'],
        ],
        'dast10_substance' => [
            'moderate' => ['dept' => 'behavioral_health', 'goal' => 'BH referral for substance-use evaluation (DAST-10 moderate)'],
            'substantial' => ['dept' => 'behavioral_health', 'goal' => 'BH referral for substance-use evaluation (DAST-10 substantial)'],
            'severe' => ['dept' => 'behavioral_health', 'goal' => 'Urgent BH + SW referral for substance-use evaluation (DAST-10 severe)'],
        ],
    ];

    /** Return a referral suggestion for a scored result, or null. */
    public function referralFor(string $instrument, string $band): ?array
    {
        return self::REFERRAL_SUGGESTIONS[$instrument][$band] ?? null;
    }

    /**
     * Instrument definition — questions, options, score weights.
     * Safe to expose to the browser.
     */
    public function definition(string $instrument): ?array
    {
        return match ($instrument) {
            'phq9_depression'   => $this->phq9(),
            'mini_cog'          => $this->miniCog(),
            'fall_risk_morse'   => $this->morse(),
            'katz_adl'          => $this->katzAdl(),
            'audit_c_alcohol'   => $this->auditC(),
            'cage_alcohol'      => $this->cage(),
            'dast10_substance'  => $this->dast10(),
            default             => null,
        };
    }

    /**
     * Score a response map for the given instrument.
     *
     * @param array<string, mixed> $responses  keyed by question id
     * @return array{total:int, max:int, interpretation:string, band:string}|null
     */
    public function score(string $instrument, array $responses): ?array
    {
        $def = $this->definition($instrument);
        if (! $def) return null;

        $total = 0;
        foreach ($def['questions'] as $q) {
            $answer = $responses[$q['id']] ?? null;
            if ($answer === null || $answer === '') continue;

            // Map answer → weight from the option list
            $weight = 0;
            foreach ($q['options'] as $opt) {
                if ((string) $opt['value'] === (string) $answer) {
                    $weight = (int) $opt['weight'];
                    break;
                }
            }
            $total += $weight;
        }

        $band = $this->bandFor($instrument, $total);

        return [
            'total'          => $total,
            'max'            => (int) $def['max_score'],
            'interpretation' => $band['interpretation'],
            'band'           => $band['band'],
        ];
    }

    private function bandFor(string $instrument, int $total): array
    {
        return match ($instrument) {
            // Phase C2b — substance-use screening
            'audit_c_alcohol' => match (true) {
                // Unified ≥4 cutoff for screening inclusivity (see memory note).
                $total >= 4 => ['band' => 'positive', 'interpretation' => 'Positive screen for at-risk drinking (AUDIT-C ≥4). Confirmatory evaluation recommended.'],
                default     => ['band' => 'negative', 'interpretation' => 'Negative screen (AUDIT-C 0–3).'],
            },
            'cage_alcohol' => match (true) {
                $total >= 2 => ['band' => 'positive', 'interpretation' => 'Positive CAGE screen (≥2) — alcohol-use evaluation indicated.'],
                default     => ['band' => 'negative', 'interpretation' => 'Negative CAGE screen (0–1).'],
            },
            'dast10_substance' => match (true) {
                $total === 0 => ['band' => 'none',         'interpretation' => 'No substance-use concerns (DAST-10 = 0).'],
                $total <= 2  => ['band' => 'low',          'interpretation' => 'Low level of problems (DAST-10 1–2).'],
                $total <= 5  => ['band' => 'moderate',     'interpretation' => 'Moderate level of problems (DAST-10 3–5) — further assessment advised.'],
                $total <= 8  => ['band' => 'substantial',  'interpretation' => 'Substantial level of problems (DAST-10 6–8) — assessment + treatment referral.'],
                default      => ['band' => 'severe',       'interpretation' => 'Severe level of problems (DAST-10 9–10) — intensive intervention required.'],
            },
            'phq9_depression' => match (true) {
                $total <= 4  => ['band' => 'minimal',          'interpretation' => 'Minimal or no depression (0–4)'],
                $total <= 9  => ['band' => 'mild',             'interpretation' => 'Mild depression (5–9)'],
                $total <= 14 => ['band' => 'moderate',         'interpretation' => 'Moderate depression (10–14)'],
                $total <= 19 => ['band' => 'moderately_severe','interpretation' => 'Moderately severe depression (15–19)'],
                default      => ['band' => 'severe',           'interpretation' => 'Severe depression (20–27) — evaluate urgently'],
            },
            'mini_cog' => match (true) {
                $total <= 2  => ['band' => 'positive', 'interpretation' => 'Positive screen for cognitive impairment (0–2)'],
                default      => ['band' => 'negative', 'interpretation' => 'Negative screen — no impairment detected (3–5)'],
            },
            'fall_risk_morse' => match (true) {
                $total <= 24  => ['band' => 'low',    'interpretation' => 'Low fall risk (0–24)'],
                $total <= 44  => ['band' => 'medium', 'interpretation' => 'Moderate fall risk (25–44)'],
                default       => ['band' => 'high',   'interpretation' => 'High fall risk (≥45) — implement interventions'],
            },
            'katz_adl' => match (true) {
                $total >= 6 => ['band' => 'independent',       'interpretation' => 'Fully independent in all ADLs (6/6)'],
                $total >= 4 => ['band' => 'moderate',          'interpretation' => 'Moderate functional impairment'],
                default     => ['band' => 'severe',            'interpretation' => 'Severe functional impairment — care plan review indicated'],
            },
            default => ['band' => 'unknown', 'interpretation' => ''],
        };
    }

    // ── Instrument definitions ─────────────────────────────────────────────

    // ── Phase C2b instruments ──────────────────────────────────────────────

    private function auditC(): array
    {
        $freq = [
            ['value' => 0, 'label' => 'Never',             'weight' => 0],
            ['value' => 1, 'label' => 'Monthly or less',   'weight' => 1],
            ['value' => 2, 'label' => '2–4 times / month', 'weight' => 2],
            ['value' => 3, 'label' => '2–3 times / week',  'weight' => 3],
            ['value' => 4, 'label' => '4+ times / week',   'weight' => 4],
        ];
        $drinks = [
            ['value' => 0, 'label' => '1 or 2',            'weight' => 0],
            ['value' => 1, 'label' => '3 or 4',            'weight' => 1],
            ['value' => 2, 'label' => '5 or 6',            'weight' => 2],
            ['value' => 3, 'label' => '7 to 9',            'weight' => 3],
            ['value' => 4, 'label' => '10 or more',        'weight' => 4],
        ];
        $binge = [
            ['value' => 0, 'label' => 'Never',             'weight' => 0],
            ['value' => 1, 'label' => 'Less than monthly', 'weight' => 1],
            ['value' => 2, 'label' => 'Monthly',           'weight' => 2],
            ['value' => 3, 'label' => 'Weekly',            'weight' => 3],
            ['value' => 4, 'label' => 'Daily or almost daily','weight' => 4],
        ];
        return [
            'instrument'  => 'audit_c_alcohol',
            'title'       => 'AUDIT-C (Alcohol Use Screening)',
            'description' => 'WHO AUDIT-C — 3-item alcohol-use screen. Positive ≥4.',
            'max_score'   => 12,
            'questions'   => [
                ['id' => 'q1', 'text' => 'How often do you have a drink containing alcohol?', 'options' => $freq],
                ['id' => 'q2', 'text' => 'How many standard drinks do you have on a typical day when you are drinking?', 'options' => $drinks],
                ['id' => 'q3', 'text' => 'How often do you have 6 or more drinks on one occasion?', 'options' => $binge],
            ],
        ];
    }

    private function cage(): array
    {
        $yesNo = [
            ['value' => 'no',  'label' => 'No',  'weight' => 0],
            ['value' => 'yes', 'label' => 'Yes', 'weight' => 1],
        ];
        return [
            'instrument'  => 'cage_alcohol',
            'title'       => 'CAGE (Alcohol-Use Screening)',
            'description' => 'Four yes/no items. Score ≥2 is a positive screen.',
            'max_score'   => 4,
            'questions'   => [
                ['id' => 'c1', 'text' => 'Have you ever felt you should Cut down on your drinking?',       'options' => $yesNo],
                ['id' => 'c2', 'text' => 'Have people Annoyed you by criticizing your drinking?',          'options' => $yesNo],
                ['id' => 'c3', 'text' => 'Have you ever felt Guilty about drinking?',                      'options' => $yesNo],
                ['id' => 'c4', 'text' => 'Have you ever had a drink first thing in the morning (Eye-opener) to steady your nerves or get rid of a hangover?', 'options' => $yesNo],
            ],
        ];
    }

    private function dast10(): array
    {
        // DAST-10 is mostly "yes = 1 point" but item 3 is reverse-scored
        // (no = 1). We encode per-item option weights so the scorer handles
        // this automatically without special-casing.
        $yes1 = [
            ['value' => 'no',  'label' => 'No',  'weight' => 0],
            ['value' => 'yes', 'label' => 'Yes', 'weight' => 1],
        ];
        $no1 = [
            ['value' => 'no',  'label' => 'No',  'weight' => 1],
            ['value' => 'yes', 'label' => 'Yes', 'weight' => 0],
        ];
        $qs = [
            ['id' => 'd1',  'text' => 'Have you used drugs other than those required for medical reasons?', 'options' => $yes1],
            ['id' => 'd2',  'text' => 'Do you abuse more than one drug at a time?', 'options' => $yes1],
            ['id' => 'd3',  'text' => 'Are you always able to stop using drugs when you want to? (reverse-scored)', 'options' => $no1],
            ['id' => 'd4',  'text' => 'Have you had blackouts or flashbacks as a result of drug use?', 'options' => $yes1],
            ['id' => 'd5',  'text' => 'Do you ever feel bad or guilty about your drug use?', 'options' => $yes1],
            ['id' => 'd6',  'text' => 'Does your spouse (or parents) ever complain about your involvement with drugs?', 'options' => $yes1],
            ['id' => 'd7',  'text' => 'Have you neglected your family because of drug use?', 'options' => $yes1],
            ['id' => 'd8',  'text' => 'Have you engaged in illegal activities to obtain drugs?', 'options' => $yes1],
            ['id' => 'd9',  'text' => 'Have you ever experienced withdrawal symptoms when you stopped?', 'options' => $yes1],
            ['id' => 'd10', 'text' => 'Have you had medical problems as a result of your drug use?', 'options' => $yes1],
        ];
        return [
            'instrument'  => 'dast10_substance',
            'title'       => 'DAST-10 (Drug Abuse Screening Test)',
            'description' => '10-item substance-use screen. 0=none, 1-2=low, 3-5=moderate, 6-8=substantial, 9-10=severe.',
            'max_score'   => 10,
            'questions'   => $qs,
        ];
    }

    private function phq9(): array
    {
        $options = [
            ['value' => 0, 'label' => 'Not at all',             'weight' => 0],
            ['value' => 1, 'label' => 'Several days',           'weight' => 1],
            ['value' => 2, 'label' => 'More than half the days','weight' => 2],
            ['value' => 3, 'label' => 'Nearly every day',       'weight' => 3],
        ];
        $qs = [
            'Little interest or pleasure in doing things',
            'Feeling down, depressed, or hopeless',
            'Trouble falling/staying asleep, or sleeping too much',
            'Feeling tired or having little energy',
            'Poor appetite or overeating',
            'Feeling bad about yourself or that you are a failure',
            'Trouble concentrating on things',
            'Moving or speaking slowly, or being fidgety/restless',
            'Thoughts that you would be better off dead, or of hurting yourself',
        ];
        return [
            'instrument' => 'phq9_depression',
            'title'      => 'PHQ-9 (Patient Health Questionnaire)',
            'description'=> 'Over the last 2 weeks, how often have you been bothered by any of the following problems?',
            'max_score'  => 27,
            'questions'  => array_map(fn ($i, $label) => [
                'id'      => 'q' . ($i + 1),
                'text'    => $label,
                'options' => $options,
            ], array_keys($qs), $qs),
        ];
    }

    private function miniCog(): array
    {
        return [
            'instrument' => 'mini_cog',
            'title'      => 'Mini-Cog (Cognitive Screen)',
            'description'=> '3-word recall + clock draw. License-free cognitive screening instrument.',
            'max_score'  => 5,
            'questions'  => [
                [
                    'id' => 'q1',
                    'text' => 'Three-word recall: number of words recalled unprompted (banana, sunrise, chair)',
                    'options' => [
                        ['value' => 0, 'label' => '0 words', 'weight' => 0],
                        ['value' => 1, 'label' => '1 word',  'weight' => 1],
                        ['value' => 2, 'label' => '2 words', 'weight' => 2],
                        ['value' => 3, 'label' => '3 words', 'weight' => 3],
                    ],
                ],
                [
                    'id' => 'q2',
                    'text' => 'Clock draw — numbers in correct position',
                    'options' => [
                        ['value' => 0, 'label' => 'Not correct', 'weight' => 0],
                        ['value' => 1, 'label' => 'Correct',     'weight' => 1],
                    ],
                ],
                [
                    'id' => 'q3',
                    'text' => 'Clock draw — hands set to 11:10 correctly',
                    'options' => [
                        ['value' => 0, 'label' => 'Not correct', 'weight' => 0],
                        ['value' => 1, 'label' => 'Correct',     'weight' => 1],
                    ],
                ],
            ],
        ];
    }

    private function morse(): array
    {
        return [
            'instrument' => 'fall_risk_morse',
            'title'      => 'Morse Fall Scale',
            'description'=> 'Six-item fall-risk assessment. Score ≥45 = high risk.',
            'max_score'  => 125,
            'questions'  => [
                [
                    'id' => 'history_of_falling',
                    'text' => 'History of falling in last 3 months',
                    'options' => [
                        ['value' => 'no',  'label' => 'No',  'weight' => 0],
                        ['value' => 'yes', 'label' => 'Yes', 'weight' => 25],
                    ],
                ],
                [
                    'id' => 'secondary_diagnosis',
                    'text' => 'Secondary diagnosis',
                    'options' => [
                        ['value' => 'no',  'label' => 'No',  'weight' => 0],
                        ['value' => 'yes', 'label' => 'Yes', 'weight' => 15],
                    ],
                ],
                [
                    'id' => 'ambulatory_aid',
                    'text' => 'Ambulatory aid',
                    'options' => [
                        ['value' => 'none',    'label' => 'None / bed rest / nurse assist', 'weight' => 0],
                        ['value' => 'crutch',  'label' => 'Crutches / cane / walker',       'weight' => 15],
                        ['value' => 'furniture','label'=> 'Holds onto furniture',           'weight' => 30],
                    ],
                ],
                [
                    'id' => 'iv_therapy',
                    'text' => 'IV or IV access',
                    'options' => [
                        ['value' => 'no',  'label' => 'No',  'weight' => 0],
                        ['value' => 'yes', 'label' => 'Yes', 'weight' => 20],
                    ],
                ],
                [
                    'id' => 'gait',
                    'text' => 'Gait / transferring',
                    'options' => [
                        ['value' => 'normal',   'label' => 'Normal / bed rest / immobile', 'weight' => 0],
                        ['value' => 'weak',     'label' => 'Weak',                          'weight' => 10],
                        ['value' => 'impaired', 'label' => 'Impaired',                      'weight' => 20],
                    ],
                ],
                [
                    'id' => 'mental_status',
                    'text' => 'Mental status',
                    'options' => [
                        ['value' => 'oriented', 'label' => 'Oriented to own ability',     'weight' => 0],
                        ['value' => 'forgets',  'label' => 'Forgets / overestimates',    'weight' => 15],
                    ],
                ],
            ],
        ];
    }

    private function katzAdl(): array
    {
        $yesNo = [
            ['value' => 'independent', 'label' => 'Independent', 'weight' => 1],
            ['value' => 'dependent',   'label' => 'Dependent',   'weight' => 0],
        ];
        $qs = [
            'bathing'    => 'Bathing',
            'dressing'   => 'Dressing',
            'toileting'  => 'Toileting',
            'transferring'=> 'Transferring',
            'continence' => 'Continence',
            'feeding'    => 'Feeding',
        ];
        return [
            'instrument' => 'katz_adl',
            'title'      => 'Katz Index of Independence in ADLs',
            'description'=> 'Six items; score 6/6 = fully independent.',
            'max_score'  => 6,
            'questions'  => array_map(fn ($id, $label) => [
                'id' => $id, 'text' => $label, 'options' => $yesNo,
            ], array_keys($qs), array_values($qs)),
        ];
    }
}
