<?php

// ─── Phase R11 — CMS PACE Audit Protocol 2.0 universe pulls ────────────────
namespace Tests\Feature;

use App\Models\CmsAuditUniverseAttempt;
use App\Models\Participant;
use App\Models\Sdr;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class R11CmsAuditUniverseTest extends TestCase
{
    use RefreshDatabase;

    private function setupAudit(): array
    {
        $t = Tenant::factory()->create();
        $site = Site::factory()->create(['tenant_id' => $t->id, 'mrn_prefix' => 'AU']);
        $u = User::factory()->create([
            'tenant_id' => $t->id, 'site_id' => $site->id,
            'department' => 'qa_compliance', 'role' => 'admin', 'is_active' => true,
        ]);
        $p = Participant::factory()->enrolled()->forTenant($t->id)->forSite($site->id)->create();
        return [$t, $u, $p, $site];
    }

    public function test_index_renders_with_4_universe_buckets(): void
    {
        [$t, $u] = $this->setupAudit();
        $this->actingAs($u);
        $this->get('/compliance/cms-audit-universes')
            ->assertOk()
            ->assertInertia(fn ($pg) => $pg
                ->component('Compliance/CmsAuditUniverses')
                ->has('universes.sdr')
                ->has('universes.grievances')
                ->has('universes.disenrollments')
                ->has('universes.appeals')
            );
    }

    public function test_first_three_attempts_export_then_fourth_is_409(): void
    {
        [$t, $u, $p, $site] = $this->setupAudit();
        Sdr::create([
            'tenant_id' => $t->id, 'participant_id' => $p->id,
            'requesting_user_id' => $u->id, 'requesting_department' => 'primary_care',
            'assigned_department' => 'social_work', 'request_type' => 'other',
            'priority' => 'routine', 'description' => 'test SDR',
            'created_at' => now()->subDays(20), 'updated_at' => now()->subDays(20),
        ]);

        $this->actingAs($u);
        $auditId = 'PACE-TEST-Q1';
        $period = '&audit_id=' . $auditId
            . '&from=' . now()->subMonths(2)->toDateString()
            . '&to=' . now()->toDateString();

        for ($i = 1; $i <= 3; $i++) {
            $r = $this->get("/compliance/cms-audit-universes/sdr.csv?_={$i}{$period}");
            $r->assertOk();
        }
        // 4th rejected
        $this->get("/compliance/cms-audit-universes/sdr.csv?_=4{$period}")->assertStatus(409);

        $this->assertEquals(3, CmsAuditUniverseAttempt::forTenant($t->id)
            ->forAudit($auditId)
            ->forUniverse('sdr')->count());
    }

    public function test_unknown_universe_returns_404(): void
    {
        [$t, $u] = $this->setupAudit();
        $this->actingAs($u);
        $this->get('/compliance/cms-audit-universes/bogus.csv')->assertStatus(404);
    }
}
