<?php

// ─── Phase S5 — IBNR estimator ─────────────────────────────────────────────
namespace Tests\Feature;

use App\Models\EncounterLog;
use App\Models\Participant;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\User;
use App\Services\IbnrEstimateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class S5IbnrEstimateTest extends TestCase
{
    use RefreshDatabase;

    public function test_ibnr_increases_when_some_encounters_are_unsubmitted(): void
    {
        $t = Tenant::factory()->create();
        $site = Site::factory()->create(['tenant_id' => $t->id, 'mrn_prefix' => 'IB']);
        $u = User::factory()->create([
            'tenant_id' => $t->id, 'site_id' => $site->id,
            'department' => 'finance', 'role' => 'admin', 'is_active' => true,
        ]);
        $p = Participant::factory()->enrolled()->forTenant($t->id)->forSite($site->id)->create();

        // 8 encounters last month, 6 submitted, 2 not.
        for ($i = 0; $i < 8; $i++) {
            EncounterLog::create([
                'tenant_id' => $t->id, 'participant_id' => $p->id,
                'service_date' => now()->subMonth()->setDay(min(28, $i + 1))->toDateString(),
                'service_type' => 'office', 'procedure_code' => '99213',
                'created_by_user_id' => $u->id, 'charge_amount' => 100.00,
                'submitted_at' => $i < 6 ? now()->subDays(20) : null,
                'submission_status' => $i < 6 ? 'submitted' : 'pending',
            ]);
        }

        $est = app(IbnrEstimateService::class)->estimate($t->id, 3);
        $this->assertGreaterThan(0, $est['total_estimated_ibnr_count']);
        $this->assertGreaterThan(0, $est['total_estimated_ibnr_dollars']);
    }

    public function test_inertia_page_renders_with_finance_user(): void
    {
        $t = Tenant::factory()->create();
        $u = User::factory()->create([
            'tenant_id' => $t->id, 'department' => 'finance',
            'role' => 'admin', 'is_active' => true,
        ]);
        $this->actingAs($u);
        $this->get('/billing/ibnr')->assertOk()
            ->assertInertia(fn ($p) => $p->component('Billing/Ibnr'));
    }

    public function test_unauthorized_dept_blocked(): void
    {
        $t = Tenant::factory()->create();
        $u = User::factory()->create([
            'tenant_id' => $t->id, 'department' => 'activities',
            'role' => 'standard', 'is_active' => true,
        ]);
        $this->actingAs($u)->get('/billing/ibnr')->assertStatus(403);
    }
}
