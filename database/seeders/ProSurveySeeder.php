<?php

// ─── ProSurveySeeder ──────────────────────────────────────────────────────────
// Seeds the catalog of Patient-Reported Outcome (PRO) survey templates that
// participants can be sent — e.g. weekly mood check, monthly pain check.
// Defines the question shape; per-participant responses are captured at
// runtime, not seeded here.
//
// When to run: always (provisions reference data) — needed for the PRO survey
// feature to render any options.
// Depends on: nothing.
// Acronyms: PRO = Patient-Reported Outcome.
// ─────────────────────────────────────────────────────────────────────────────

namespace Database\Seeders;

use App\Models\ProSurvey;
use Illuminate\Database\Seeder;

class ProSurveySeeder extends Seeder
{
    public function run(): void
    {
        $rows = [
            [
                'key' => 'mood_weekly', 'title' => 'Weekly Mood Check',
                'questions' => [
                    ['id' => 'q1', 'text' => 'Overall mood this week (0-10)', 'type' => 'number', 'min' => 0, 'max' => 10],
                    ['id' => 'q2', 'text' => 'Trouble sleeping (0-3)',        'type' => 'number', 'min' => 0, 'max' => 3],
                ],
                'cadence' => 'weekly',
            ],
            [
                'key' => 'pain_weekly', 'title' => 'Weekly Pain Score',
                'questions' => [
                    ['id' => 'q1', 'text' => 'Worst pain this week (0-10)',   'type' => 'number', 'min' => 0, 'max' => 10],
                    ['id' => 'q2', 'text' => 'Interference with daily life (0-10)', 'type' => 'number', 'min' => 0, 'max' => 10],
                ],
                'cadence' => 'weekly',
            ],
            [
                'key' => 'function_weekly', 'title' => 'Weekly Function',
                'questions' => [
                    ['id' => 'q1', 'text' => 'Ability to walk 1 block (0-3 where 3=easy)', 'type' => 'number', 'min' => 0, 'max' => 3],
                    ['id' => 'q2', 'text' => 'Ability to prepare a meal (0-3)',  'type' => 'number', 'min' => 0, 'max' => 3],
                    ['id' => 'q3', 'text' => 'Ability to take medications on schedule (0-3)', 'type' => 'number', 'min' => 0, 'max' => 3],
                ],
                'cadence' => 'weekly',
            ],
        ];
        foreach ($rows as $r) {
            ProSurvey::updateOrCreate(['tenant_id' => null, 'key' => $r['key']], $r);
        }
        $this->command?->info('    PRO surveys seeded (' . count($rows) . ').');
    }
}
