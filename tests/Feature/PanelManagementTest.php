<?php

namespace Tests\Feature;

use App\Models\Participant;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PanelManagementTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private Site $site;
    private User $pcpA;
    private User $pcpB;
    private User $qa;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::factory()->create();
        $this->site = Site::factory()->create(['tenant_id' => $this->tenant->id, 'mrn_prefix' => 'PM']);
        $this->pcpA = User::factory()->create(['tenant_id' => $this->tenant->id, 'department' => 'primary_care', 'role' => 'admin', 'is_active' => true, 'site_id' => $this->site->id]);
        $this->pcpB = User::factory()->create(['tenant_id' => $this->tenant->id, 'department' => 'primary_care', 'role' => 'admin', 'is_active' => true, 'site_id' => $this->site->id]);
        $this->qa   = User::factory()->create(['tenant_id' => $this->tenant->id, 'department' => 'qa_compliance', 'role' => 'admin', 'is_active' => true, 'site_id' => $this->site->id]);
    }

    private function mkPanel(int $pcpId, int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            Participant::factory()->enrolled()
                ->forTenant($this->tenant->id)->forSite($this->site->id)
                ->create(['primary_care_user_id' => $pcpId]);
        }
    }

    public function test_my_panel_returns_only_my_participants(): void
    {
        $this->mkPanel($this->pcpA->id, 3);
        $this->mkPanel($this->pcpB->id, 2);
        $this->actingAs($this->pcpA);
        $r = $this->getJson('/panel/my');
        $r->assertOk();
        $this->assertEquals(3, $r->json('panel_size'));
    }

    public function test_assign_changes_pcp(): void
    {
        $p = Participant::factory()->enrolled()->forTenant($this->tenant->id)->forSite($this->site->id)
            ->create(['primary_care_user_id' => $this->pcpA->id]);
        $this->actingAs($this->qa);
        $this->postJson('/panel/assign', [
            'participant_id' => $p->id, 'pcp_user_id' => $this->pcpB->id,
        ])->assertOk();
        $this->assertEquals($this->pcpB->id, $p->fresh()->primary_care_user_id);
    }

    public function test_bulk_transfer_moves_participants(): void
    {
        $this->mkPanel($this->pcpA->id, 4);
        $this->actingAs($this->qa);
        $r = $this->postJson('/panel/transfer', [
            'from_pcp_user_id' => $this->pcpA->id,
            'to_pcp_user_id'   => $this->pcpB->id,
        ]);
        $r->assertOk();
        $this->assertEquals(4, $r->json('transferred'));
        $this->assertEquals(0, Participant::where('primary_care_user_id', $this->pcpA->id)->count());
        $this->assertEquals(4, Participant::where('primary_care_user_id', $this->pcpB->id)->count());
    }

    public function test_sizes_returns_each_pcp(): void
    {
        $this->mkPanel($this->pcpA->id, 5);
        $this->mkPanel($this->pcpB->id, 3);
        $this->actingAs($this->qa);
        $r = $this->getJson('/panel/sizes');
        $r->assertOk();
        $sizes = collect($r->json('rows'));
        $this->assertEquals(5, $sizes->firstWhere('pcp_id', $this->pcpA->id)['panel_size']);
        $this->assertEquals(3, $sizes->firstWhere('pcp_id', $this->pcpB->id)['panel_size']);
    }

    public function test_cross_tenant_assign_blocked(): void
    {
        $other = Tenant::factory()->create();
        $oSite = Site::factory()->create(['tenant_id' => $other->id, 'mrn_prefix' => 'XX']);
        $otherP = Participant::factory()->enrolled()->forTenant($other->id)->forSite($oSite->id)->create();
        $this->actingAs($this->qa);
        $this->postJson('/panel/assign', [
            'participant_id' => $otherP->id, 'pcp_user_id' => $this->pcpA->id,
        ])->assertStatus(403);
    }

    public function test_non_clinical_cannot_access_panel(): void
    {
        $finance = User::factory()->create(['tenant_id' => $this->tenant->id, 'department' => 'finance', 'role' => 'admin', 'is_active' => true]);
        $this->actingAs($finance);
        $this->getJson('/panel/my')->assertStatus(403);
    }
}
