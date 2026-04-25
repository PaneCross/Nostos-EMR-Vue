<?php

// ─── Phase R9 — Marketing / referral-source / lead funnel ──────────────────
namespace Tests\Feature;

use App\Models\Referral;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class R9MarketingFunnelTest extends TestCase
{
    use RefreshDatabase;

    public function test_marketing_funnel_aggregates_by_source_and_status(): void
    {
        $t = Tenant::factory()->create();
        $site = Site::factory()->create(['tenant_id' => $t->id, 'mrn_prefix' => 'MK']);
        $u = User::factory()->create([
            'tenant_id' => $t->id, 'site_id' => $site->id,
            'department' => 'enrollment', 'role' => 'admin', 'is_active' => true,
        ]);

        // 3 hospital referrals: 1 enrolled, 1 declined, 1 in pipeline.
        Referral::factory()->create(['tenant_id' => $t->id, 'site_id' => $site->id,
            'referral_source' => 'hospital', 'status' => 'enrolled', 'referral_date' => now()->subMonths(2)]);
        Referral::factory()->create(['tenant_id' => $t->id, 'site_id' => $site->id,
            'referral_source' => 'hospital', 'status' => 'declined', 'decline_reason' => 'income_too_high', 'referral_date' => now()->subMonths(3)]);
        Referral::factory()->create(['tenant_id' => $t->id, 'site_id' => $site->id,
            'referral_source' => 'hospital', 'status' => 'intake_in_progress', 'referral_date' => now()->subMonths(1)]);
        // 1 family referral, declined.
        Referral::factory()->create(['tenant_id' => $t->id, 'site_id' => $site->id,
            'referral_source' => 'family', 'status' => 'declined', 'decline_reason' => 'income_too_high', 'referral_date' => now()->subMonths(2)]);

        $this->actingAs($u);
        $this->get('/enrollment/marketing-funnel')
            ->assertOk()
            ->assertInertia(fn ($p) => $p
                ->component('Enrollment/MarketingFunnel')
                ->where('totals.leads', 4)
                ->where('totals.enrolled', 1)
                ->where('totals.declined', 2)
                ->has('by_source', 2)
                ->where('by_source.0.source', 'hospital')
                ->where('by_source.0.total', 3)
                ->where('by_source.0.enrolled', 1)
                ->has('decline_reasons')
            );
    }

    public function test_non_authorized_dept_gets_403(): void
    {
        $t = Tenant::factory()->create();
        $u = User::factory()->create([
            'tenant_id' => $t->id, 'department' => 'activities',
            'role' => 'standard', 'is_active' => true,
        ]);
        $this->actingAs($u);
        $this->get('/enrollment/marketing-funnel')->assertStatus(403);
    }
}
