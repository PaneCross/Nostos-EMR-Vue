<?php

// ─── Phase S2 — Contracted-provider network + per-CPT rates ────────────────
namespace Tests\Feature;

use App\Models\ContractedProvider;
use App\Models\ContractedProviderContract;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class S2ContractedProviderTest extends TestCase
{
    use RefreshDatabase;

    private function setupNet(): array
    {
        $t = Tenant::factory()->create();
        $u = User::factory()->create([
            'tenant_id' => $t->id, 'department' => 'finance',
            'role' => 'admin', 'is_active' => true,
        ]);
        return [$t, $u];
    }

    public function test_create_provider_then_contract_then_rate(): void
    {
        [$t, $u] = $this->setupNet();
        $this->actingAs($u);

        $r = $this->postJson('/network/contracted-providers', [
            'name' => 'Acme Cardiology', 'provider_type' => 'specialist',
            'specialty' => 'Cardiology', 'state' => 'CA',
        ])->assertStatus(201);
        $providerId = $r->json('provider.id');

        $r = $this->postJson("/network/contracted-providers/{$providerId}/contracts", [
            'effective_date' => now()->subYear()->toDateString(),
            'reimbursement_basis' => 'percent_of_medicare',
            'reimbursement_value' => 80.0,
        ])->assertStatus(201);
        $contractId = $r->json('contract.id');

        $this->postJson("/network/contracts/{$contractId}/rates", [
            'cpt_code' => '99213', 'rate_amount' => 92.50,
        ])->assertStatus(201);

        $contract = ContractedProviderContract::find($contractId);
        $this->assertEquals(92.50, $contract->rateFor('99213'));

        $provider = ContractedProvider::find($providerId);
        $this->assertEquals('percent_of_medicare', $provider->activeContract()->reimbursement_basis);
    }

    public function test_index_renders_inertia_page(): void
    {
        [$t, $u] = $this->setupNet();
        $this->actingAs($u);
        $this->get('/network/contracted-providers')
            ->assertOk()
            ->assertInertia(fn ($p) => $p->component('Network/ContractedProviders'));
    }

    public function test_unauthorized_dept_blocked(): void
    {
        $t = Tenant::factory()->create();
        $u = User::factory()->create([
            'tenant_id' => $t->id, 'department' => 'activities',
            'role' => 'standard', 'is_active' => true,
        ]);
        $this->actingAs($u)->getJson('/network/contracted-providers')->assertStatus(403);
    }
}
