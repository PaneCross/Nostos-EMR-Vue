<?php

// ─── Phase P10 — Wave I-N factory coverage ─────────────────────────────────
namespace Tests\Feature;

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
use App\Models\Site;
use App\Models\StaffTask;
use App\Models\TbScreening;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class P10FactoryCoverageTest extends TestCase
{
    use RefreshDatabase;

    private function tenant(): Tenant { return Tenant::factory()->create(); }

    public function test_iadl_record_factory(): void
    {
        $r = IadlRecord::factory()->create();
        $this->assertNotNull($r->id);
    }

    public function test_tb_screening_factory(): void
    {
        $r = TbScreening::factory()->create();
        $this->assertNotNull($r->id);
    }

    public function test_anticoagulation_plan_factory(): void
    {
        $r = AnticoagulationPlan::factory()->create();
        $this->assertNotNull($r->id);
    }

    public function test_inr_result_factory(): void
    {
        $r = InrResult::factory()->create();
        $this->assertNotNull($r->id);
    }

    public function test_adverse_drug_event_factory(): void
    {
        $r = AdverseDrugEvent::factory()->create();
        $this->assertNotNull($r->id);
    }

    public function test_bereavement_contact_factory(): void
    {
        $r = BereavementContact::factory()->create();
        $this->assertNotNull($r->id);
    }

    public function test_discharge_event_factory(): void
    {
        $r = DischargeEvent::factory()->create();
        $this->assertNotNull($r->id);
    }

    public function test_care_gap_factory(): void
    {
        $r = CareGap::factory()->create();
        $this->assertNotNull($r->id);
    }

    public function test_goals_of_care_factory(): void
    {
        $r = GoalsOfCareConversation::factory()->create();
        $this->assertNotNull($r->id);
    }

    public function test_predictive_risk_score_factory(): void
    {
        $r = PredictiveRiskScore::factory()->create();
        $this->assertNotNull($r->id);
    }

    public function test_dietary_order_factory(): void
    {
        $r = DietaryOrder::factory()->create();
        $this->assertNotNull($r->id);
    }

    public function test_activity_event_factory(): void
    {
        $t = $this->tenant();
        $site = Site::factory()->create(['tenant_id' => $t->id]);
        $r = ActivityEvent::factory()->create(['tenant_id' => $t->id, 'site_id' => $site->id]);
        $this->assertNotNull($r->id);
    }

    public function test_staff_task_factory(): void
    {
        $r = StaffTask::factory()->create();
        $this->assertNotNull($r->id);
    }

    public function test_saved_dashboard_factory(): void
    {
        $r = SavedDashboard::factory()->create();
        $this->assertNotNull($r->id);
    }
}
