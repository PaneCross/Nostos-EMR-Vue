<?php

// ─── Phase I6 — Pharmacy + PCP dashboard widget wiring ──────────────────────
namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class I6DashboardWidgetsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::factory()->create();
    }

    private function pharmacyUser(): User
    {
        return User::factory()->create([
            'tenant_id' => $this->tenant->id, 'department' => 'pharmacy',
            'role' => 'standard', 'is_active' => true,
        ]);
    }

    private function pcpUser(): User
    {
        return User::factory()->create([
            'tenant_id' => $this->tenant->id, 'department' => 'primary_care',
            'role' => 'standard', 'is_active' => true,
        ]);
    }

    private function wrongDeptUser(): User
    {
        return User::factory()->create([
            'tenant_id' => $this->tenant->id, 'department' => 'dietary',
            'role' => 'standard', 'is_active' => true,
        ]);
    }

    // ── Pharmacy widgets ───────────────────────────────────────────────

    public function test_pharmacy_widgets_return_expected_shape(): void
    {
        $u = $this->pharmacyUser();
        $this->actingAs($u);

        $this->getJson('/dashboards/pharmacy/bcma-overrides')
            ->assertOk()
            ->assertJsonStructure(['rows', 'total']);

        $this->getJson('/dashboards/pharmacy/beers-rollup')
            ->assertOk()
            ->assertJsonStructure(['participants_with_pims', 'enrolled_total', 'top_pim_categories']);

        $this->getJson('/dashboards/pharmacy/medwatch-deadlines')
            ->assertOk()
            ->assertJsonStructure(['rows', 'total']);

        $this->getJson('/dashboards/pharmacy/polypharmacy-queue')
            ->assertOk()
            ->assertJsonStructure(['rows', 'total']);
    }

    public function test_wrong_dept_cannot_access_pharmacy_widgets(): void
    {
        $u = $this->wrongDeptUser();
        $this->actingAs($u);

        $this->getJson('/dashboards/pharmacy/bcma-overrides')->assertForbidden();
        $this->getJson('/dashboards/pharmacy/beers-rollup')->assertForbidden();
        $this->getJson('/dashboards/pharmacy/medwatch-deadlines')->assertForbidden();
        $this->getJson('/dashboards/pharmacy/polypharmacy-queue')->assertForbidden();
    }

    // ── PCP widgets ────────────────────────────────────────────────────

    public function test_pcp_widgets_return_expected_shape(): void
    {
        $u = $this->pcpUser();
        $this->actingAs($u);

        $this->getJson('/dashboards/primary-care/care-gaps-rollup')
            ->assertOk()
            ->assertJsonStructure(['rows', 'total_open']);

        $this->getJson('/dashboards/primary-care/high-risk-panel')
            ->assertOk()
            ->assertJsonStructure(['rows', 'total']);

        $this->getJson('/dashboards/primary-care/inr-overdue')
            ->assertOk()
            ->assertJsonStructure(['rows', 'total']);
    }

    public function test_wrong_dept_cannot_access_pcp_widgets(): void
    {
        $u = $this->wrongDeptUser();
        $this->actingAs($u);

        $this->getJson('/dashboards/primary-care/care-gaps-rollup')->assertForbidden();
        $this->getJson('/dashboards/primary-care/high-risk-panel')->assertForbidden();
        $this->getJson('/dashboards/primary-care/inr-overdue')->assertForbidden();
    }
}
