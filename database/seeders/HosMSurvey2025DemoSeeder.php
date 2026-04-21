<?php

namespace Database\Seeders;

use App\Models\HosMSurvey;
use App\Models\Participant;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * Seeds a mix of 2025 HOS-M surveys across the demo tenant so the year
 * selector has historical data to switch to.
 *
 * Creates ~20 surveys distributed across:
 *  - most submitted to CMS (historical year is usually fully wrapped up)
 *  - a few complete but not yet submitted (late straggler)
 *  - a couple incomplete (drop-offs)
 *
 * Idempotent: uses firstOrCreate on (tenant_id, participant_id, survey_year).
 */
class HosMSurvey2025DemoSeeder extends Seeder
{
    public function run(): void
    {
        $tenant = Tenant::where('slug', 'sunrise-pace-demo')->firstOrFail();

        $administerUser = User::where('tenant_id', $tenant->id)
            ->whereIn('department', ['finance', 'primary_care'])
            ->first();

        if (! $administerUser) {
            $this->command->info('  No finance/primary_care user found — skipping 2025 HOS-M seed.');
            return;
        }

        $participants = Participant::where('tenant_id', $tenant->id)
            ->where('enrollment_status', 'enrolled')
            ->where('is_active', true)
            ->orderBy('id')
            ->limit(22)
            ->get();

        if ($participants->count() < 10) {
            $this->command->info('  Not enough enrolled participants — skipping 2025 HOS-M seed.');
            return;
        }

        $count = ['submitted' => 0, 'complete' => 0, 'incomplete' => 0];

        foreach ($participants as $i => $p) {
            // Distribution: first 15 submitted, next 4 complete-not-submitted,
            // last 3 incomplete. Roughly mirrors a closed-out year with a few
            // stragglers and drop-offs.
            $bucket = match (true) {
                $i < 15  => 'submitted',
                $i < 19  => 'complete',
                default  => 'incomplete',
            };

            // Vary the administered date across the year for realism
            $administeredAt = Carbon::create(2025, rand(2, 11), rand(1, 28), rand(8, 16), rand(0, 59));

            $attrs = [
                'administered_by_user_id' => $administerUser->id,
                'administered_at'         => $administeredAt,
            ];

            switch ($bucket) {
                case 'submitted':
                    $attrs['completed']        = true;
                    $attrs['submitted_to_cms'] = true;
                    // CMS submission typically within 30-60 days of administration
                    $attrs['submitted_at']     = $administeredAt->copy()->addDays(rand(14, 55));
                    $attrs['responses']        = $this->fakeResponses();
                    $count['submitted']++;
                    break;

                case 'complete':
                    $attrs['completed']        = true;
                    $attrs['submitted_to_cms'] = false;
                    $attrs['submitted_at']     = null;
                    $attrs['responses']        = $this->fakeResponses();
                    $count['complete']++;
                    break;

                case 'incomplete':
                    $attrs['completed']        = false;
                    $attrs['submitted_to_cms'] = false;
                    $attrs['submitted_at']     = null;
                    // Incomplete surveys often have partial responses
                    $attrs['responses']        = $this->fakePartialResponses();
                    $count['incomplete']++;
                    break;
            }

            HosMSurvey::firstOrCreate(
                [
                    'tenant_id'      => $tenant->id,
                    'participant_id' => $p->id,
                    'survey_year'    => 2025,
                ],
                $attrs,
            );
        }

        $total = array_sum($count);
        $this->command->info(sprintf(
            '  Seeded %d 2025 HOS-M surveys: %d submitted to CMS, %d complete (pending), %d incomplete.',
            $total, $count['submitted'], $count['complete'], $count['incomplete'],
        ));
    }

    /** HOS-M fully answered responses. 1 = Excellent, 5 = Poor. */
    private function fakeResponses(): array
    {
        return [
            'physical_health' => rand(2, 4),  // mostly fair/good for elder PACE cohort
            'mental_health'   => rand(2, 4),
            'pain'            => rand(2, 4),
            'falls_past_year' => (string) rand(0, 1),
            'fall_injuries'   => (string) rand(0, 1),
        ];
    }

    /** HOS-M partially-filled responses (simulates an in-progress survey). */
    private function fakePartialResponses(): array
    {
        return [
            'physical_health' => rand(2, 4),
            'mental_health'   => rand(2, 4),
            // pain, falls_past_year, fall_injuries intentionally omitted
        ];
    }
}
