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
    ];

    /**
     * Instrument definition — questions, options, score weights.
     * Safe to expose to the browser.
     */
    public function definition(string $instrument): ?array
    {
        return match ($instrument) {
            'phq9_depression' => $this->phq9(),
            'mini_cog'        => $this->miniCog(),
            'fall_risk_morse' => $this->morse(),
            'katz_adl'        => $this->katzAdl(),
            default           => null,
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
