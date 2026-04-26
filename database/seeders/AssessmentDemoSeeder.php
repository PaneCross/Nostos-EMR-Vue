<?php

// ─── AssessmentDemoSeeder ─────────────────────────────────────────────────────
// Seeds a small set of demo Assessment records (clinical scoring instruments
// like PHQ-9, Mini-Cog, Morse, Katz ADL) attached to enrolled participants so
// the Assessments tab and trend views are not empty in a fresh demo tenant.
//
// When to run: demo only.
// Depends on: DemoEnvironmentSeeder (needs tenant + participants + users).
// ─────────────────────────────────────────────────────────────────────────────

namespace Database\Seeders;

use App\Models\Assessment;
use App\Models\Participant;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

class AssessmentDemoSeeder extends Seeder
{
    public function run(): void
    {
        $tenant = Tenant::where('slug', 'sunrise-pace-demo')->firstOrFail();
        $participants = Participant::where('tenant_id', $tenant->id)
            ->where('enrollment_status', 'enrolled')
            ->limit(10)
            ->get();

        if ($participants->isEmpty()) {
            $this->command->info('  No enrolled participants found — skipping assessment seeding.');
            return;
        }

        $author = User::where('tenant_id', $tenant->id)
            ->where('department', 'primary_care')
            ->first();

        $authorId = $author?->id;
        $count = 0;

        // ── Overdue assessments (next_due_date in the past) ──────────────────
        $overdueSpecs = [
            ['type' => 'annual_reassessment', 'dept' => 'primary_care', 'score' => null],
            ['type' => 'fall_risk_morse',     'dept' => 'primary_care', 'score' => 45],
            ['type' => 'phq9_depression',     'dept' => 'behavioral_health', 'score' => 8],
            ['type' => 'nutritional',         'dept' => 'dietary', 'score' => null],
        ];

        foreach ($overdueSpecs as $i => $spec) {
            $p = $participants[$i % $participants->count()];
            Assessment::create([
                'participant_id'      => $p->id,
                'tenant_id'           => $tenant->id,
                'authored_by_user_id' => $authorId,
                'department'          => $spec['dept'],
                'assessment_type'     => $spec['type'],
                'responses'           => $this->responsesFor($spec['type']),
                'score'               => $spec['score'],
                'completed_at'        => Carbon::now()->subMonths(7),
                'next_due_date'       => Carbon::now()->subDays(rand(5, 45)),
                'threshold_flags'     => null,
            ]);
            $count++;
        }

        // ── Due soon assessments (next_due_date within 14 days) ──────────────
        $dueSoonSpecs = [
            ['type' => 'braden_scale',    'dept' => 'primary_care', 'score' => 16],
            ['type' => 'moca_cognitive',  'dept' => 'primary_care', 'score' => 24],
            ['type' => 'oral_health',     'dept' => 'primary_care', 'score' => 5],
            ['type' => 'gad7_anxiety',    'dept' => 'behavioral_health', 'score' => 6],
            ['type' => 'pain_scale',      'dept' => 'primary_care', 'score' => 3],
        ];

        foreach ($dueSoonSpecs as $i => $spec) {
            $p = $participants[($i + 4) % $participants->count()];
            Assessment::create([
                'participant_id'      => $p->id,
                'tenant_id'           => $tenant->id,
                'authored_by_user_id' => $authorId,
                'department'          => $spec['dept'],
                'assessment_type'     => $spec['type'],
                'responses'           => $this->responsesFor($spec['type']),
                'score'               => $spec['score'],
                'completed_at'        => Carbon::now()->subMonths(6),
                'next_due_date'       => Carbon::now()->addDays(rand(1, 12)),
                'threshold_flags'     => null,
            ]);
            $count++;
        }

        // ── Recently completed (due date far out) ────────────────────────────
        $recentSpecs = [
            ['type' => 'phq9_depression',      'dept' => 'behavioral_health', 'score' => 4],
            ['type' => 'fall_risk_morse',       'dept' => 'primary_care', 'score' => 20],
            ['type' => 'braden_scale',          'dept' => 'primary_care', 'score' => 19],
            ['type' => 'initial_comprehensive', 'dept' => 'primary_care', 'score' => null],
            ['type' => 'mmse_cognitive',        'dept' => 'primary_care', 'score' => 28],
            ['type' => 'adl_functional',        'dept' => 'home_care', 'score' => null],
        ];

        foreach ($recentSpecs as $i => $spec) {
            $p = $participants[($i + 2) % $participants->count()];
            Assessment::create([
                'participant_id'      => $p->id,
                'tenant_id'           => $tenant->id,
                'authored_by_user_id' => $authorId,
                'department'          => $spec['dept'],
                'assessment_type'     => $spec['type'],
                'responses'           => $this->responsesFor($spec['type']),
                'score'               => $spec['score'],
                'completed_at'        => Carbon::now()->subDays(rand(1, 14)),
                'next_due_date'       => Carbon::now()->addMonths(6),
                'threshold_flags'     => null,
            ]);
            $count++;
        }

        $this->command->info("  Seeded {$count} assessments (4 overdue, 5 due soon, 6 recently completed).");
    }

    private function responsesFor(string $type): array
    {
        return match ($type) {
            'phq9_depression' => ['q1' => '1', 'q2' => '0', 'q3' => '1', 'q4' => '0', 'q5' => '1', 'q6' => '0', 'q7' => '0', 'q8' => '1', 'q9' => '0'],
            'gad7_anxiety'    => ['q1' => '1', 'q2' => '1', 'q3' => '0', 'q4' => '1', 'q5' => '1', 'q6' => '1', 'q7' => '1'],
            'fall_risk_morse' => ['fall_history' => '25', 'secondary_diagnosis' => '0', 'ambulatory_aid' => '15', 'iv_access' => '0', 'gait' => '10', 'mental_status' => '0'],
            'mmse_cognitive'  => ['orientation_time' => '5', 'orientation_place' => '5', 'registration' => '3', 'attention' => '5', 'recall' => '3', 'language' => '7'],
            'braden_scale'    => ['sensory_perception' => '3', 'moisture' => '3', 'activity' => '3', 'mobility' => '3', 'nutrition' => '3', 'friction' => '2'],
            'moca_cognitive'  => ['visuospatial' => '4', 'naming' => '3', 'attention' => '5', 'language' => '3', 'abstraction' => '2', 'memory' => '4', 'orientation' => '6'],
            'oral_health'     => ['lips' => '0', 'tongue' => '1', 'gums' => '1', 'saliva' => '0', 'teeth' => '1', 'dentures' => '0', 'oral_cleanliness' => '1', 'dental_pain' => '1'],
            'pain_scale'      => ['location' => 'lower back', 'intensity' => '3', 'character' => 'aching', 'aggravating' => 'standing', 'alleviating' => 'rest'],
            default           => ['notes' => 'Assessment completed per schedule.', 'findings' => 'Within normal limits.'],
        };
    }
}
