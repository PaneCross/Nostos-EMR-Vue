<?php

namespace Tests\Feature;

use App\Models\Participant;
use App\Models\SavedDashboard;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\User;
use App\Services\ReportBuilderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdvancedBiTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private Site $site;
    private User $qa;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::factory()->create();
        $this->site = Site::factory()->create(['tenant_id' => $this->tenant->id, 'mrn_prefix' => 'G9']);
        $this->qa = User::factory()->create(['tenant_id' => $this->tenant->id, 'department' => 'qa_compliance', 'role' => 'admin', 'is_active' => true, 'site_id' => $this->site->id]);
    }

    public function test_schema_returns_whitelists(): void
    {
        $this->actingAs($this->qa);
        $r = $this->getJson('/bi/schema');
        $r->assertOk();
        $this->assertContains('participants', $r->json('entities'));
        $this->assertContains('count', $r->json('measures'));
    }

    public function test_participants_by_enrollment_status_returns_chart_shape(): void
    {
        for ($i = 0; $i < 3; $i++) {
            Participant::factory()->enrolled()->forTenant($this->tenant->id)->forSite($this->site->id)->create();
        }
        $this->actingAs($this->qa);
        $r = $this->postJson('/bi/report', [
            'entity'    => 'participants',
            'dimension' => 'emr_participants.enrollment_status',
            'measure'   => 'count',
        ]);
        $r->assertOk();
        $this->assertNotEmpty($r->json('labels'));
        $this->assertNotEmpty($r->json('datasets.0.data'));
    }

    public function test_unknown_entity_is_rejected(): void
    {
        $this->actingAs($this->qa);
        $this->postJson('/bi/report', [
            'entity' => 'sql_injection', 'dimension' => 'emr_participants.gender',
        ])->assertStatus(422);
    }

    public function test_unknown_dimension_is_rejected(): void
    {
        $this->actingAs($this->qa);
        $this->postJson('/bi/report', [
            'entity' => 'participants', 'dimension' => 'emr_participants.ssn',
        ])->assertStatus(422);
    }

    public function test_save_and_retrieve_dashboard(): void
    {
        $this->actingAs($this->qa);
        $this->postJson('/bi/dashboards', [
            'title' => 'QAPI overview',
            'widgets' => [
                ['type' => 'bar', 'report' => ['entity' => 'participants', 'dimension' => 'emr_participants.gender']],
            ],
            'is_shared' => true,
        ])->assertStatus(201);
        $this->assertEquals(1, SavedDashboard::count());

        $r = $this->getJson('/bi/dashboards');
        $r->assertOk();
        $this->assertCount(1, $r->json('dashboards'));
    }

    public function test_private_dashboard_blocks_non_owner(): void
    {
        $other = User::factory()->create(['tenant_id' => $this->tenant->id, 'department' => 'qa_compliance', 'role' => 'admin', 'is_active' => true]);
        $d = SavedDashboard::create([
            'tenant_id' => $this->tenant->id, 'owner_user_id' => $this->qa->id,
            'title' => 'Private', 'widgets' => [], 'is_shared' => false,
        ]);
        $this->actingAs($other);
        $this->getJson("/bi/dashboards/{$d->id}")->assertStatus(403);
    }
}
