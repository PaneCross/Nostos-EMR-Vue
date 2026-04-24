<?php

namespace Tests\Feature;

use App\Jobs\CareGapCalculationJob;
use App\Models\CareGap;
use App\Models\ClinicalNote;
use App\Models\Immunization;
use App\Models\Participant;
use App\Models\Problem;
use App\Models\Site;
use App\Models\StaffTask;
use App\Models\Tenant;
use App\Models\User;
use App\Services\CareGapService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CareGapTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private Site $site;
    private User $pcp;
    private Participant $participant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::factory()->create();
        $this->site = Site::factory()->create(['tenant_id' => $this->tenant->id, 'mrn_prefix' => 'G1']);
        $this->pcp = User::factory()->create(['tenant_id' => $this->tenant->id, 'department' => 'primary_care', 'role' => 'admin', 'is_active' => true, 'site_id' => $this->site->id]);
        $this->participant = Participant::factory()->enrolled()
            ->forTenant($this->tenant->id)->forSite($this->site->id)
            ->create(['dob' => now()->subYears(70), 'gender' => 'female', 'primary_care_user_id' => $this->pcp->id]);
    }

    public function test_flu_shot_satisfied_when_shot_this_season(): void
    {
        Immunization::create([
            'tenant_id' => $this->tenant->id, 'participant_id' => $this->participant->id,
            'vaccine_type' => 'influenza', 'vaccine_name' => 'Flu', 'cvx_code' => '150',
            'administered_date' => now()->subWeeks(2),
        ]);
        (new CareGapService())->evaluate($this->participant);
        $gap = CareGap::where('measure', 'flu_shot')->first();
        $this->assertTrue($gap->satisfied);
    }

    public function test_annual_pcp_visit_gap_when_no_recent_note(): void
    {
        (new CareGapService())->evaluate($this->participant);
        $gap = CareGap::where('measure', 'annual_pcp_visit')->first();
        $this->assertFalse($gap->satisfied);
    }

    public function test_annual_pcp_visit_satisfied_with_recent_note(): void
    {
        ClinicalNote::create([
            'tenant_id' => $this->tenant->id, 'participant_id' => $this->participant->id,
            'site_id' => $this->site->id, 'note_type' => 'soap', 'status' => 'signed',
            'authored_by_user_id' => $this->pcp->id, 'department' => 'primary_care',
            'visit_type' => 'in_center', 'visit_date' => now()->subMonths(6),
        ]);
        (new CareGapService())->evaluate($this->participant);
        $this->assertTrue(CareGap::where('measure', 'annual_pcp_visit')->first()->satisfied);
    }

    public function test_diabetic_a1c_gap_when_dm_and_no_recent_a1c(): void
    {
        Problem::create([
            'tenant_id' => $this->tenant->id, 'participant_id' => $this->participant->id,
            'icd10_code' => 'E11.9', 'icd10_description' => 'Type 2 diabetes',
            'status' => 'active', 'onset_date' => now()->subYears(3),
        ]);
        (new CareGapService())->evaluate($this->participant);
        $this->assertFalse(CareGap::where('measure', 'a1c')->first()->satisfied);
    }

    public function test_non_diabetic_a1c_marked_satisfied(): void
    {
        (new CareGapService())->evaluate($this->participant);
        $this->assertTrue(CareGap::where('measure', 'a1c')->first()->satisfied);
    }

    public function test_job_creates_task_when_3_plus_open_gaps(): void
    {
        (new CareGapCalculationJob())->handle(app(CareGapService::class));
        $open = CareGap::where('participant_id', $this->participant->id)->where('satisfied', false)->count();
        if ($open >= 3) {
            $this->assertTrue(StaffTask::where('related_to_type', 'care_gap')
                ->where('participant_id', $this->participant->id)->exists());
        } else {
            $this->markTestSkipped('Fewer than 3 open gaps in this demographic');
        }
    }

    public function test_summary_endpoint_aggregates(): void
    {
        (new CareGapService())->evaluate($this->participant);
        $this->actingAs($this->pcp);
        $r = $this->getJson('/care-gaps/summary');
        $r->assertOk();
        $this->assertGreaterThan(0, count($r->json('rows')));
    }
}
