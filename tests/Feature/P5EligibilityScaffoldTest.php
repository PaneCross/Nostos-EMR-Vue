<?php

// ─── Phase P5 — X12 270/271 eligibility scaffold ───────────────────────────
namespace Tests\Feature;

use App\Models\EligibilityCheck;
use App\Models\Participant;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Eligibility\AvailityEligibilityGateway;
use App\Services\Eligibility\ChangeHealthcareEligibilityGateway;
use App\Services\Eligibility\NullEligibilityGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class P5EligibilityScaffoldTest extends TestCase
{
    use RefreshDatabase;

    private function setupTenant(): array
    {
        $t = Tenant::factory()->create();
        $prefix = strtoupper(\Illuminate\Support\Str::random(3));
        $site = Site::factory()->create(['tenant_id' => $t->id, 'mrn_prefix' => $prefix]);
        $p = Participant::factory()->enrolled()->forTenant($t->id)->forSite($site->id)->create();
        $u = User::factory()->create([
            'tenant_id' => $t->id, 'department' => 'enrollment',
            'role' => 'admin', 'is_active' => true,
        ]);
        return [$t, $u, $p];
    }

    public function test_null_gateway_returns_unverified_with_honest_label(): void
    {
        [$t, $u, $p] = $this->setupTenant();
        $gw = new NullEligibilityGateway();
        $r = $gw->check($p, 'medicare', null);
        $this->assertEquals('unverified', $r['status']);
        $this->assertStringContainsString('scaffold-only', $r['payload']['honest_label']);
    }

    public function test_real_gateway_stubs_throw(): void
    {
        [$t, $u, $p] = $this->setupTenant();
        $availity = new AvailityEligibilityGateway();
        $this->expectException(\RuntimeException::class);
        $availity->check($p, 'medicare', null);
    }

    public function test_change_healthcare_stub_throws(): void
    {
        [$t, $u, $p] = $this->setupTenant();
        $this->expectException(\RuntimeException::class);
        (new ChangeHealthcareEligibilityGateway())->check($p, 'medicaid', null);
    }

    public function test_endpoint_persists_eligibility_check_row(): void
    {
        [$t, $u, $p] = $this->setupTenant();
        $this->actingAs($u);
        $r = $this->postJson("/participants/{$p->id}/eligibility-checks", [
            'payer_type' => 'medicare',
            'member_id_lookup' => '1EG4-TE5-MK72',
        ]);
        $r->assertStatus(201)->assertJsonStructure(['check', 'gateway']);
        $this->assertEquals(1, EligibilityCheck::forTenant($t->id)->count());
        $row = EligibilityCheck::first();
        $this->assertEquals('unverified', $row->response_status);
        $this->assertEquals('null', $row->gateway_used);
    }
}
