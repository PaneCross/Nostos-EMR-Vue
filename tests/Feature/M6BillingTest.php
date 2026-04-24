<?php

// ─── Phase M6 — State adapters + billing reconciliation dashboards ─────────
namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Services\StateMedicaid\CaMedicaidAdapter;
use App\Services\StateMedicaid\FlMedicaidAdapter;
use App\Services\StateMedicaid\NyMedicaidAdapter;
use App\Services\StateMedicaid\StateAdapterFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class M6BillingTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $dept = 'finance'): User
    {
        $t = Tenant::factory()->create();
        return User::factory()->create([
            'tenant_id' => $t->id, 'department' => $dept, 'role' => 'admin', 'is_active' => true,
        ]);
    }

    public function test_ca_adapter_wraps_header(): void
    {
        $out = (new CaMedicaidAdapter())->transform('ISA...', ['tenant_id' => 1]);
        $this->assertStringContainsString('CA-MEDS', $out);
        $this->assertStringContainsString('ISA...', $out);
    }

    public function test_ny_adapter_wraps_header(): void
    {
        $out = (new NyMedicaidAdapter())->transform('ISA...', ['tenant_id' => 1]);
        $this->assertStringContainsString('eMedNY', $out);
    }

    public function test_fl_adapter_wraps_header(): void
    {
        $out = (new FlMedicaidAdapter())->transform('ISA...', ['tenant_id' => 1]);
        $this->assertStringContainsString('MMIS', $out);
    }

    public function test_factory_returns_null_for_unknown_state(): void
    {
        $this->assertNull(StateAdapterFactory::for('XX'));
        $this->assertInstanceOf(CaMedicaidAdapter::class, StateAdapterFactory::for('CA'));
    }

    public function test_pde_reconciliation_json_endpoint(): void
    {
        $this->actingAs($this->user());
        $this->getJson('/billing/pde-reconciliation.json')
            ->assertOk()->assertJsonStructure(['rows']);
    }

    public function test_capitation_reconciliation_json_endpoint(): void
    {
        $this->actingAs($this->user());
        $this->getJson('/billing/capitation-reconciliation.json')
            ->assertOk()->assertJsonStructure(['rows']);
    }

    public function test_pde_dashboard_page_renders(): void
    {
        $this->actingAs($this->user());
        $this->get('/dashboards/pde-reconciliation')->assertOk()
            ->assertInertia(fn ($p) => $p->component('Dashboards/PdeReconciliation'));
    }

    public function test_capitation_dashboard_page_renders(): void
    {
        $this->actingAs($this->user());
        $this->get('/dashboards/capitation-reconciliation')->assertOk()
            ->assertInertia(fn ($p) => $p->component('Dashboards/CapitationReconciliation'));
    }
}
