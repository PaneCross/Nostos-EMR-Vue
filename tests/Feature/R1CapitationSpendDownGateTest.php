<?php

// ─── Phase R1 — CapitationController gates Medicaid cap on spend-down ──────
namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\CapitationRecord;
use App\Models\InsuranceCoverage;
use App\Models\Participant;
use App\Models\Site;
use App\Models\SpendDownPayment;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class R1CapitationSpendDownGateTest extends TestCase
{
    use RefreshDatabase;

    private function setupFinance(): array
    {
        $t = Tenant::factory()->create();
        $site = Site::factory()->create(['tenant_id' => $t->id, 'mrn_prefix' => 'CAP']);
        $u = User::factory()->create([
            'tenant_id' => $t->id, 'site_id' => $site->id,
            'department' => 'finance', 'role' => 'admin', 'is_active' => true,
        ]);
        $p = Participant::factory()->enrolled()->forTenant($t->id)->forSite($site->id)->create();
        InsuranceCoverage::create([
            'participant_id' => $p->id, 'payer_type' => 'medicaid',
            'member_id' => 'M1', 'plan_name' => 'CA Medi-Cal',
            'effective_date' => now()->startOfYear()->toDateString(), 'is_active' => true,
            'share_of_cost_monthly_amount' => 600.00, 'spend_down_state' => 'CA',
        ]);
        return [$t, $u, $p];
    }

    public function test_capitation_blocked_when_spend_down_unmet(): void
    {
        [$t, $u, $p] = $this->setupFinance();
        $period = now()->format('Y-m');

        $r = $this->actingAs($u)->postJson('/billing/capitation', [
            'participant_id' => $p->id,
            'month_year' => $period,
            'medicare_a_rate' => 1000, 'medicare_b_rate' => 800,
            'medicare_d_rate' => 200, 'medicaid_rate' => 1500,
            'total_capitation' => 3500,
        ]);
        $r->assertStatus(422)->assertJsonPath('error', 'spend_down_unmet');
        $this->assertEquals(0, CapitationRecord::count());
        $this->assertEquals(1, AuditLog::where('action', 'billing.capitation.blocked_spend_down')->count());
    }

    public function test_capitation_allowed_when_spend_down_met(): void
    {
        [$t, $u, $p] = $this->setupFinance();
        $period = now()->format('Y-m');
        SpendDownPayment::create([
            'tenant_id' => $t->id,
            'participant_id' => $p->id, 'period_month_year' => $period,
            'amount' => 600.00, 'paid_at' => now(),
            'payment_method' => 'check',
            'recorded_by_user_id' => $u->id,
        ]);

        $r = $this->actingAs($u)->postJson('/billing/capitation', [
            'participant_id' => $p->id,
            'month_year' => $period,
            'medicare_a_rate' => 1000, 'medicare_b_rate' => 800,
            'medicare_d_rate' => 200, 'medicaid_rate' => 1500,
            'total_capitation' => 3500,
        ]);
        $r->assertStatus(201);
        $this->assertEquals(1, CapitationRecord::count());
    }
}
