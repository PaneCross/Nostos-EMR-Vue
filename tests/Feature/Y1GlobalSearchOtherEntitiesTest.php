<?php

// ─── Phase Y1 — GlobalSearch coverage for referrals/orders/sdrs + cross-tenant
// Audit-13 polish-sweep follow-up to Audit-12 L1: Phase14WinsTest covered
// participants/grievances/appointments. The other three group keys
// (referrals, orders, sdrs) were never asserted, and tenant scoping was
// only spot-checked on /participants/search — never on the global /search.
namespace Tests\Feature;

use App\Models\ClinicalOrder;
use App\Models\Participant;
use App\Models\Referral;
use App\Models\Sdr;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class Y1GlobalSearchOtherEntitiesTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private Site $site;
    private User $user;
    private Participant $participant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::factory()->create();
        $this->site   = Site::factory()->create(['tenant_id' => $this->tenant->id, 'mrn_prefix' => 'Y1A']);
        $this->user   = User::factory()->create([
            'tenant_id' => $this->tenant->id, 'site_id' => $this->site->id,
            'department' => 'primary_care', 'role' => 'admin', 'is_active' => true,
        ]);
        $this->participant = Participant::factory()->enrolled()
            ->forTenant($this->tenant->id)->forSite($this->site->id)
            ->create(['first_name' => 'Bartholomew', 'last_name' => 'Y1Search']);
    }

    public function test_global_search_returns_referrals_by_prospective_name(): void
    {
        Referral::factory()->create([
            'tenant_id' => $this->tenant->id,
            'site_id'   => $this->site->id,
            'prospective_first_name' => 'Y1Unique',
            'prospective_last_name'  => 'Referral',
        ]);

        $r = $this->actingAs($this->user)->getJson('/search?q=Y1Unique');
        $r->assertOk();
        $this->assertNotEmpty($r->json('groups.referrals'));
        $this->assertEquals('referral', $r->json('groups.referrals.0.kind'));
    }

    public function test_global_search_returns_orders_by_order_type(): void
    {
        ClinicalOrder::factory()->create([
            'tenant_id'       => $this->tenant->id,
            'site_id'         => $this->site->id,
            'participant_id'  => $this->participant->id,
            'instructions'    => 'unique-Y1-instruction-string',
        ]);

        $r = $this->actingAs($this->user)->getJson('/search?q=unique-Y1-instruction');
        $r->assertOk();
        $this->assertNotEmpty($r->json('groups.orders'));
        $this->assertEquals('order', $r->json('groups.orders.0.kind'));
    }

    public function test_global_search_returns_sdrs_by_description(): void
    {
        Sdr::factory()->create([
            'participant_id' => $this->participant->id,
            'tenant_id'      => $this->tenant->id,
            'description'    => 'A very-Y1-distinctive sdr description.',
        ]);

        $r = $this->actingAs($this->user)->getJson('/search?q=very-Y1-distinctive');
        $r->assertOk();
        $this->assertNotEmpty($r->json('groups.sdrs'));
        $this->assertEquals('sdr', $r->json('groups.sdrs.0.kind'));
    }

    public function test_global_search_does_not_leak_other_tenants(): void
    {
        $other = Tenant::factory()->create();
        $otherSite = Site::factory()->create(['tenant_id' => $other->id, 'mrn_prefix' => 'Y1B']);
        Referral::factory()->create([
            'tenant_id' => $other->id,
            'site_id'   => $otherSite->id,
            'prospective_first_name' => 'CrossTenantY1',
            'prospective_last_name'  => 'LeakProbe',
        ]);

        $r = $this->actingAs($this->user)->getJson('/search?q=CrossTenantY1');
        $r->assertOk();
        $this->assertEmpty($r->json('groups.referrals') ?: []);
    }
}
