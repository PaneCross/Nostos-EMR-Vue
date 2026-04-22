<?php

// ─── SpendDownTest ────────────────────────────────────────────────────────────
// Phase 7 (MVP roadmap) — Medicaid spend-down / share-of-cost tracking.
// ─────────────────────────────────────────────────────────────────────────────

namespace Tests\Feature;

use App\Models\InsuranceCoverage;
use App\Models\Participant;
use App\Models\Site;
use App\Models\SpendDownPayment;
use App\Models\Tenant;
use App\Models\User;
use App\Services\SpendDownService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SpendDownTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private Site $site;
    private User $financeUser;
    private Participant $participant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::factory()->create();
        $this->site   = Site::factory()->create(['tenant_id' => $this->tenant->id, 'mrn_prefix' => 'SPD']);
        $this->financeUser = User::factory()->create([
            'tenant_id'  => $this->tenant->id,
            'department' => 'finance',
            'role'       => 'admin',
            'is_active'  => true,
        ]);
        $this->participant = Participant::factory()->enrolled()
            ->forTenant($this->tenant->id)
            ->forSite($this->site->id)
            ->create();

        InsuranceCoverage::create([
            'participant_id'               => $this->participant->id,
            'payer_type'                   => 'medicaid',
            'plan_name'                    => 'Medi-Cal',
            'effective_date'               => now()->subYear()->toDateString(),
            'is_active'                    => true,
            'share_of_cost_monthly_amount' => 500.00,
            'spend_down_state'             => 'CA',
            'spend_down_period_start'      => now()->startOfYear()->toDateString(),
            'spend_down_period_end'        => now()->endOfYear()->toDateString(),
        ]);
    }

    private function payment(string $periodYm, float $amount, string $method = 'check'): SpendDownPayment
    {
        return SpendDownPayment::create([
            'tenant_id'           => $this->tenant->id,
            'participant_id'      => $this->participant->id,
            'amount'              => $amount,
            'paid_at'             => now()->toDateString(),
            'period_month_year'   => $periodYm,
            'payment_method'      => $method,
            'recorded_by_user_id' => $this->financeUser->id,
        ]);
    }

    // ── Service tests ────────────────────────────────────────────────────────

    public function test_period_status_returns_obligation_and_paid_and_remaining(): void
    {
        $periodYm = now()->format('Y-m');
        $this->payment($periodYm, 200.00);

        $svc = app(SpendDownService::class);
        $status = $svc->periodStatus($this->participant, $periodYm);

        $this->assertNotNull($status);
        $this->assertEquals(500.00, $status['obligation']);
        $this->assertEquals(200.00, $status['paid']);
        $this->assertEquals(300.00, $status['remaining']);
        $this->assertFalse($status['met']);
    }

    public function test_period_status_marks_met_when_fully_paid(): void
    {
        $periodYm = now()->format('Y-m');
        $this->payment($periodYm, 250.00);
        $this->payment($periodYm, 250.00, 'eft');

        $svc = app(SpendDownService::class);
        $status = $svc->periodStatus($this->participant, $periodYm);

        $this->assertTrue($status['met']);
        $this->assertEquals(0.0, $status['remaining']);
    }

    public function test_capitation_blocked_while_unmet_unblocks_when_met(): void
    {
        $periodYm = now()->format('Y-m');
        $svc = app(SpendDownService::class);

        $this->assertTrue($svc->capitationBlocked($this->participant, $periodYm));

        $this->payment($periodYm, 500.00);
        $this->assertFalse($svc->capitationBlocked($this->participant, $periodYm));
    }

    public function test_overdue_for_tenant_includes_unmet_prior_periods(): void
    {
        $priorYm = now()->subMonth()->format('Y-m');
        $this->payment($priorYm, 100.00); // way short

        $svc = app(SpendDownService::class);
        $rows = $svc->overdueForTenant($this->tenant->id, 3);

        $match = collect($rows)->firstWhere('period', $priorYm);
        $this->assertNotNull($match, 'Expected overdue row for prior month');
        $this->assertEquals($this->participant->id, $match['participant_id']);
        $this->assertEquals(400.00, $match['remaining']);
    }

    // ── Controller tests ─────────────────────────────────────────────────────

    public function test_store_payment_creates_row_and_writes_audit_log(): void
    {
        $this->actingAs($this->financeUser);

        $periodYm = now()->format('Y-m');
        $resp = $this->postJson("/participants/{$this->participant->id}/spend-down/payments", [
            'amount'            => 500.00,
            'paid_at'           => now()->toDateString(),
            'period_month_year' => $periodYm,
            'payment_method'    => 'check',
            'reference_number'  => 'CHK-TEST-001',
        ]);

        $resp->assertSuccessful();
        $this->assertDatabaseHas('emr_spend_down_payments', [
            'participant_id'    => $this->participant->id,
            'amount'            => 500.00,
            'period_month_year' => $periodYm,
        ]);
        $this->assertDatabaseHas('shared_audit_logs', [
            'action' => 'spend_down.payment_recorded',
        ]);
    }

    public function test_store_payment_rejects_non_authorized_department(): void
    {
        $nurse = User::factory()->create([
            'tenant_id'  => $this->tenant->id,
            'department' => 'primary_care',
            'role'       => 'standard',
            'is_active'  => true,
        ]);
        $this->actingAs($nurse);

        $resp = $this->postJson("/participants/{$this->participant->id}/spend-down/payments", [
            'amount'            => 500.00,
            'paid_at'           => now()->toDateString(),
            'period_month_year' => now()->format('Y-m'),
            'payment_method'    => 'check',
        ]);

        $resp->assertForbidden();
    }

    public function test_tenant_isolation_blocks_cross_tenant_access(): void
    {
        $otherTenant = Tenant::factory()->create();
        $otherUser = User::factory()->create([
            'tenant_id'  => $otherTenant->id,
            'department' => 'finance',
            'role'       => 'admin',
            'is_active'  => true,
        ]);
        $this->actingAs($otherUser);

        $resp = $this->getJson("/participants/{$this->participant->id}/spend-down");
        $this->assertTrue(in_array($resp->status(), [403, 404]), 'Expected cross-tenant access to be blocked');
    }
}
