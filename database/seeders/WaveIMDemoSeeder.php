<?php

// ─── WaveIMDemoSeeder — Phase O9 ────────────────────────────────────────────
// Populates demo data for every model added in Waves I-M so a freshly seeded
// tenant has non-empty IADL, TB, Anticoagulation, ADE, Hospice, Discharge,
// CareGap, GoalsOfCare, PredictiveRisk, Dietary, Activity, StaffTask, and
// SavedDashboard data. Without this seeder, every Wave I-N tab/dashboard
// renders empty on the demo.
//
// Idempotent: skips a tenant if it already has WaveI-M data (checks IadlRecord).
// ─────────────────────────────────────────────────────────────────────────────

namespace Database\Seeders;

use App\Models\ActivityEvent;
use App\Models\AdverseDrugEvent;
use App\Models\AnticoagulationPlan;
use App\Models\BereavementContact;
use App\Models\CareGap;
use App\Models\DietaryOrder;
use App\Models\DischargeEvent;
use App\Models\GoalsOfCareConversation;
use App\Models\IadlRecord;
use App\Models\InrResult;
use App\Models\Participant;
use App\Models\PredictiveRiskScore;
use App\Models\SavedDashboard;
use App\Models\StaffTask;
use App\Models\TbScreening;
use App\Models\Tenant;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

class WaveIMDemoSeeder extends Seeder
{
    public function run(): void
    {
        Tenant::all()->each(fn (Tenant $t) => $this->seedTenant($t));
    }

    private function seedTenant(Tenant $tenant): void
    {
        if (IadlRecord::forTenant($tenant->id)->exists()) {
            $this->command?->info("  → Skipping tenant {$tenant->id}: WaveI-M demo data already present.");
            return;
        }

        $participants = Participant::where('tenant_id', $tenant->id)
            ->where('enrollment_status', 'enrolled')
            ->orderBy('id')->limit(3)->get();
        if ($participants->isEmpty()) return;

        $clinicalUser = User::where('tenant_id', $tenant->id)
            ->where('department', 'primary_care')->first()
            ?? User::where('tenant_id', $tenant->id)->first();
        if (! $clinicalUser) return;

        [$alice, $bob, $cara] = [
            $participants[0],
            $participants[1] ?? $participants[0],
            $participants[2] ?? $participants[0],
        ];

        $this->seedIadl($tenant, $alice, $clinicalUser);
        $this->seedTb($tenant, $alice, $clinicalUser);
        $this->seedAnticoagulation($tenant, $alice, $clinicalUser);
        $this->seedAde($tenant, $alice, $bob);
        $this->seedHospice($tenant, $cara, $clinicalUser);
        $this->seedDischarge($tenant, $bob, $clinicalUser);
        $this->seedCareGaps($tenant, $alice);
        $this->seedGoalsOfCare($tenant, $alice, $clinicalUser);
        $this->seedPredictiveRisk($tenant, $alice, $bob, $cara);
        $this->seedDietary($tenant, $alice, $bob, $clinicalUser);
        $this->seedActivities($tenant, $clinicalUser);
        $this->seedStaffTasks($tenant, $alice, $clinicalUser);
        $this->seedSavedDashboards($tenant, $clinicalUser);

        $this->command?->info("  → Tenant {$tenant->id}: seeded WaveI-M demo data on {$participants->count()} participants.");
    }

    private function seedIadl(Tenant $t, Participant $p, User $u): void
    {
        // Two records 6 months apart so the trend sparkline has a story.
        IadlRecord::create([
            'tenant_id' => $t->id, 'participant_id' => $p->id, 'recorded_by_user_id' => $u->id,
            'recorded_at' => now()->subMonths(6),
            'telephone' => 1, 'shopping' => 1, 'food_preparation' => 1, 'housekeeping' => 1,
            'laundry' => 1, 'transportation' => 0, 'medications' => 1, 'finances' => 1,
            'total_score' => 7, 'interpretation' => 'mild_impairment',
            'notes' => 'Baseline assessment; transport assistance needed.',
        ]);
        IadlRecord::create([
            'tenant_id' => $t->id, 'participant_id' => $p->id, 'recorded_by_user_id' => $u->id,
            'recorded_at' => now()->subWeek(),
            'telephone' => 1, 'shopping' => 0, 'food_preparation' => 0, 'housekeeping' => 1,
            'laundry' => 1, 'transportation' => 0, 'medications' => 0, 'finances' => 1,
            'total_score' => 4, 'interpretation' => 'moderate_impairment',
            'notes' => 'Decline noted in shopping, meal prep, and med management.',
        ]);
    }

    private function seedTb(Tenant $t, Participant $p, User $u): void
    {
        $performed = now()->subMonths(2);
        TbScreening::create([
            'tenant_id' => $t->id, 'participant_id' => $p->id, 'recorded_by_user_id' => $u->id,
            'screening_type' => 'ppd',
            'performed_date' => $performed,
            'result' => 'negative',
            'induration_mm' => 0,
            'next_due_date' => $performed->copy()->addDays(TbScreening::RECERT_DAYS),
            'notes' => 'Annual PPD; no induration.',
        ]);
    }

    private function seedAnticoagulation(Tenant $t, Participant $p, User $u): void
    {
        $plan = AnticoagulationPlan::create([
            'tenant_id' => $t->id, 'participant_id' => $p->id,
            'agent' => 'warfarin',
            'target_inr_low' => 2.0, 'target_inr_high' => 3.0,
            'monitoring_interval_days' => 30,
            'start_date' => now()->subMonths(4),
            'prescribing_provider_user_id' => $u->id,
            'notes' => 'AFib; chronic anticoagulation.',
        ]);
        // 3 INR values — last is out-of-range so the chip color demo is visible.
        $values = [
            [now()->subMonths(3)->setHour(9), 2.5, true,  null],
            [now()->subMonths(2)->setHour(9), 2.8, true,  null],
            [now()->subWeek()->setHour(9),    3.6, false, 'Hold one warfarin dose; recheck in 3 days.'],
        ];
        foreach ($values as [$drawn, $value, $inRange, $adjust]) {
            InrResult::create([
                'tenant_id' => $t->id, 'participant_id' => $p->id,
                'anticoagulation_plan_id' => $plan->id,
                'drawn_at' => $drawn, 'value' => $value, 'in_range' => $inRange,
                'dose_adjustment_text' => $adjust,
                'recorded_by_user_id' => $u->id,
            ]);
        }
    }

    private function seedAde(Tenant $t, Participant $p1, Participant $p2): void
    {
        AdverseDrugEvent::create([
            'tenant_id' => $t->id, 'participant_id' => $p1->id,
            'onset_date' => now()->subWeeks(2),
            'severity' => 'mild',
            'reaction_description' => 'Mild rash on forearms after starting amoxicillin; resolved within 48h of discontinuation.',
            'causality' => 'probable',
            'outcome_text' => 'Resolved without intervention.',
            'auto_allergy_created' => false,
        ]);
        // Second event for a different participant — severe + auto-allergy story.
        AdverseDrugEvent::create([
            'tenant_id' => $t->id, 'participant_id' => $p2->id,
            'onset_date' => now()->subDays(5),
            'severity' => 'severe',
            'reaction_description' => 'Anaphylaxis with airway involvement; required IM epinephrine + ED visit.',
            'causality' => 'definite',
            'outcome_text' => 'Recovered after epinephrine + observation.',
            'auto_allergy_created' => true,
        ]);
    }

    private function seedHospice(Tenant $t, Participant $p, User $u): void
    {
        $p->update([
            'hospice_status'             => 'enrolled',
            'hospice_started_at'         => now()->subMonths(2)->addDays(3),
            'hospice_provider_text'      => 'Sunrise Hospice & Palliative Care',
            'hospice_diagnosis_text'     => 'End-stage CHF, NYHA Class IV.',
            'hospice_last_idt_review_at' => now()->subWeeks(4),
        ]);
        // Bereavement contacts only seeded when participant is deceased; we'll
        // create a "scheduled" contact attached to a different participant who
        // was previously deceased to populate the bereavement queue.
        BereavementContact::create([
            'tenant_id' => $t->id, 'participant_id' => $p->id,
            'contact_type' => 'day_15',
            'family_contact_name' => 'Jane (daughter)',
            'family_contact_phone' => '(555) 123-4567',
            'scheduled_at' => now()->addDays(2),
            'status' => 'scheduled',
        ]);
    }

    private function seedDischarge(Tenant $t, Participant $p, User $u): void
    {
        $dischargedOn = now()->subDays(3);
        $checklist = DischargeEvent::buildDefaultChecklist($dischargedOn);
        // Mark a couple of items completed for partial-state demo.
        $checklist[0]['completed_at'] = now()->subDays(2)->toIso8601String();
        $checklist[0]['completed_by_user_id'] = $u->id;
        $checklist[1]['completed_at'] = now()->subDays(2)->toIso8601String();
        $checklist[1]['completed_by_user_id'] = $u->id;

        DischargeEvent::create([
            'tenant_id' => $t->id, 'participant_id' => $p->id,
            'discharge_from_facility' => 'Mercy General Hospital',
            'discharged_on' => $dischargedOn,
            'readmission_risk_score' => 0.42,
            'checklist' => $checklist,
            'auto_created_from_adt' => false,
            'created_by_user_id' => $u->id,
            'notes' => 'CHF exacerbation admit; 4-day stay.',
        ]);
    }

    private function seedCareGaps(Tenant $t, Participant $p): void
    {
        $today = now()->toDateString();
        $rows = [
            ['measure' => 'annual_pcp_visit',   'satisfied' => true,  'last_satisfied_date' => now()->subMonths(3)->toDateString(), 'next_due_date' => now()->addMonths(9)->toDateString(), 'reason_open' => null],
            ['measure' => 'flu_shot',           'satisfied' => true,  'last_satisfied_date' => now()->subMonths(2)->toDateString(), 'next_due_date' => now()->addYear()->toDateString(),   'reason_open' => null],
            ['measure' => 'pneumococcal',       'satisfied' => false, 'last_satisfied_date' => null,                                'next_due_date' => now()->subWeek()->toDateString(),   'reason_open' => 'Never recorded; nurse to schedule.'],
            ['measure' => 'colonoscopy',        'satisfied' => false, 'last_satisfied_date' => now()->subYears(11)->toDateString(),'next_due_date' => now()->subMonths(2)->toDateString(),'reason_open' => 'Last colonoscopy >10 years ago.'],
            ['measure' => 'a1c',                'satisfied' => true,  'last_satisfied_date' => now()->subMonths(4)->toDateString(),'next_due_date' => now()->addMonths(2)->toDateString(), 'reason_open' => null],
        ];
        foreach ($rows as $r) {
            CareGap::create(array_merge($r, [
                'tenant_id' => $t->id, 'participant_id' => $p->id,
                'calculated_at' => now(),
            ]));
        }
    }

    private function seedGoalsOfCare(Tenant $t, Participant $p, User $u): void
    {
        GoalsOfCareConversation::create([
            'tenant_id' => $t->id, 'participant_id' => $p->id,
            'conversation_date' => now()->subMonth()->toDateString(),
            'participants_present' => 'Participant, daughter (HCP)',
            'discussion_summary' => 'Reviewed prognosis after recent hospitalization. Participant prefers comfort-focused care, wants to remain at home. Daughter understands and supports.',
            'decisions_made' => 'DNR confirmed. Hospice referral discussed; not yet ready.',
            'next_steps' => 'Re-discuss hospice in 3 months or sooner if function declines.',
            'recorded_by_user_id' => $u->id,
        ]);
        GoalsOfCareConversation::create([
            'tenant_id' => $t->id, 'participant_id' => $p->id,
            'conversation_date' => now()->subWeek()->toDateString(),
            'participants_present' => 'Participant, son (POA), social worker',
            'discussion_summary' => 'Follow-up to last month — participant reports difficulty with stairs at home. Discussed home-care augmentation.',
            'decisions_made' => 'Add 3x/week home-health aide visits.',
            'next_steps' => 'SW to coordinate with home_care; review in 30 days.',
            'recorded_by_user_id' => $u->id,
        ]);
    }

    private function seedPredictiveRisk(Tenant $t, Participant $a, Participant $b, Participant $c): void
    {
        $samples = [
            [$a, 'disenrollment', 35, 'medium'],
            [$a, 'acute_event',   78, 'high'],
            [$b, 'disenrollment', 22, 'low'],
            [$b, 'acute_event',   55, 'medium'],
            [$c, 'disenrollment', 71, 'high'],
            [$c, 'acute_event',   42, 'medium'],
        ];
        foreach ($samples as [$p, $kind, $score, $band]) {
            PredictiveRiskScore::create([
                'tenant_id' => $t->id, 'participant_id' => $p->id,
                'model_version' => 'g8-v1-demo',
                'risk_type' => $kind,
                'score' => $score, 'band' => $band,
                'factors' => [
                    'lace'           => ['value' => 0.5, 'weight' => 30, 'delta' => 15],
                    'recent_hosp'    => ['value' => 0.4, 'weight' => 25, 'delta' => 10],
                    'polypharmacy'   => ['value' => 0.6, 'weight' => 10, 'delta' => 6],
                    'adl_dependence' => ['value' => 0.3, 'weight' => 25, 'delta' => 7],
                    'age'            => ['value' => 0.7, 'weight' => 10, 'delta' => 7],
                ],
                'computed_at' => now()->subDays(rand(1, 3)),
            ]);
        }
    }

    private function seedDietary(Tenant $t, Participant $a, Participant $b, User $u): void
    {
        DietaryOrder::create([
            'tenant_id' => $t->id, 'participant_id' => $a->id, 'ordered_by_user_id' => $u->id,
            'diet_type' => 'diabetic',
            'calorie_target' => 1800,
            'fluid_restriction_ml_per_day' => null,
            'effective_date' => now()->subMonths(2),
            'rationale' => 'Type-2 diabetes; A1c 7.4 last quarter.',
        ]);
        DietaryOrder::create([
            'tenant_id' => $t->id, 'participant_id' => $b->id, 'ordered_by_user_id' => $u->id,
            'diet_type' => 'cardiac',
            'calorie_target' => 1600,
            'fluid_restriction_ml_per_day' => 1500,
            'effective_date' => now()->subWeeks(3),
            'rationale' => 'CHF; volume restriction post-hospitalization.',
        ]);
    }

    private function seedActivities(Tenant $t, User $u): void
    {
        $site = $u->site_id ?? \DB::table('emr_sites')->where('tenant_id', $t->id)->value('id');
        if (! $site) return;
        ActivityEvent::create([
            'tenant_id' => $t->id, 'site_id' => $site,
            'title' => 'Tuesday Music Therapy',
            'category' => 'creative',
            'scheduled_at' => Carbon::parse('next tuesday 10:00'),
            'duration_min' => 60,
            'location' => 'Day Center Activity Room',
            'facilitator_user_id' => $u->id,
            'description' => 'Group music therapy with live pianist.',
        ]);
        ActivityEvent::create([
            'tenant_id' => $t->id, 'site_id' => $site,
            'title' => 'Thursday Chair Yoga',
            'category' => 'physical',
            'scheduled_at' => Carbon::parse('next thursday 11:00'),
            'duration_min' => 45,
            'location' => 'Day Center Activity Room',
            'facilitator_user_id' => $u->id,
            'description' => 'Gentle stretching for participants of all mobility levels.',
        ]);
    }

    private function seedStaffTasks(Tenant $t, Participant $p, User $u): void
    {
        $tasks = [
            ['title' => 'Schedule pneumococcal vaccine for ' . $p->first_name, 'priority' => 'normal', 'due_at' => now()->addDays(3), 'status' => 'pending'],
            ['title' => 'Follow up on out-of-range INR (3.6)',                  'priority' => 'high',   'due_at' => now()->subDay(),    'status' => 'pending'],
            ['title' => 'Confirm post-discharge home-care visit',                'priority' => 'high',   'due_at' => now()->addDay(),    'status' => 'pending'],
            ['title' => 'Update advance directive paperwork',                    'priority' => 'normal', 'due_at' => now()->addWeek(),   'status' => 'pending'],
            ['title' => 'Review Beers PIM list for polypharmacy participant',    'priority' => 'low',    'due_at' => now()->addWeeks(2), 'status' => 'completed', 'completed_at' => now()->subDay()],
        ];
        foreach ($tasks as $task) {
            StaffTask::create(array_merge($task, [
                'tenant_id' => $t->id,
                'participant_id' => $p->id,
                'assigned_to_user_id' => $u->id,
                'created_by_user_id' => $u->id,
                'description' => 'Demo task seeded by WaveIMDemoSeeder.',
            ]));
        }
    }

    private function seedSavedDashboards(Tenant $t, User $u): void
    {
        SavedDashboard::create([
            'tenant_id' => $t->id, 'owner_user_id' => $u->id,
            'title' => 'Executive daily snapshot',
            'description' => 'Demo dashboard — start here, add widgets via the report builder.',
            'widgets' => [],
            'is_shared' => true,
        ]);
    }
}
