<?php

namespace Tests\Feature;

use App\Models\Alert;
use App\Models\Participant;
use App\Models\ProResponse;
use App\Models\ProSurvey;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\User;
use App\Services\AlertService;
use App\Services\ProService;
use App\Services\Sms\NullSmsGateway;
use App\Services\Sms\SmsGateway;
use Database\Seeders\ProSurveySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProSurveyTest extends TestCase
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
        $this->site = Site::factory()->create(['tenant_id' => $this->tenant->id, 'mrn_prefix' => 'G7']);
        $this->pcp = User::factory()->create(['tenant_id' => $this->tenant->id, 'department' => 'primary_care', 'role' => 'admin', 'is_active' => true, 'site_id' => $this->site->id]);
        $this->participant = Participant::factory()->enrolled()->forTenant($this->tenant->id)->forSite($this->site->id)->create();
        (new ProSurveySeeder())->run();
    }

    public function test_default_sms_gateway_is_null(): void
    {
        $this->assertInstanceOf(NullSmsGateway::class, app(SmsGateway::class));
    }

    public function test_record_response_aggregates_numerics(): void
    {
        $survey = ProSurvey::where('key', 'pain_weekly')->first();
        $svc = app(ProService::class);
        $r = $svc->recordResponse($this->participant, $survey, ['q1' => 5, 'q2' => 3]);
        $this->assertEquals(8, $r->aggregate_score);
    }

    public function test_two_consecutive_high_pain_fires_critical_alert(): void
    {
        $survey = ProSurvey::where('key', 'pain_weekly')->first();
        $svc = app(ProService::class);
        $svc->recordResponse($this->participant, $survey, ['q1' => 9, 'q2' => 0]);
        $svc->recordResponse($this->participant, $survey, ['q1' => 9, 'q2' => 0]);
        $this->assertTrue(Alert::where('alert_type', 'pro_pain_persistent')->exists());
    }

    public function test_single_high_pain_does_not_alert(): void
    {
        $survey = ProSurvey::where('key', 'pain_weekly')->first();
        app(ProService::class)->recordResponse($this->participant, $survey, ['q1' => 9, 'q2' => 0]);
        $this->assertFalse(Alert::where('alert_type', 'pro_pain_persistent')->exists());
    }

    public function test_portal_store_response_endpoint(): void
    {
        $survey = ProSurvey::where('key', 'mood_weekly')->first();
        $this->actingAs($this->pcp);
        $r = $this->postJson('/pro/responses', [
            'participant_id' => $this->participant->id,
            'survey_id' => $survey->id,
            'answers' => ['q1' => 7, 'q2' => 1],
            'delivery_channel' => 'portal',
        ]);
        $r->assertStatus(201);
        $this->assertEquals(1, ProResponse::count());
    }

    public function test_trend_endpoint_groups_by_survey(): void
    {
        $survey = ProSurvey::where('key', 'function_weekly')->first();
        $svc = app(ProService::class);
        $svc->recordResponse($this->participant, $survey, ['q1' => 2, 'q2' => 2, 'q3' => 2]);
        $this->actingAs($this->pcp);
        $r = $this->getJson("/participants/{$this->participant->id}/pro-trend");
        $r->assertOk();
        $this->assertArrayHasKey((string) $survey->id, $r->json('rows'));
    }
}
