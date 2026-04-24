<?php

// ─── Phase K1 — Inertia dashboard pages (Quality Measures + Care Gaps + High-Risk) ─
namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class K1DashboardPagesTest extends TestCase
{
    use RefreshDatabase;

    private function user(): User
    {
        $tenant = Tenant::factory()->create();
        return User::factory()->create([
            'tenant_id' => $tenant->id, 'department' => 'primary_care',
            'role' => 'admin', 'is_active' => true,
        ]);
    }

    public function test_quality_measures_page_renders(): void
    {
        $u = $this->user();
        $this->actingAs($u);
        $this->get('/dashboards/quality')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Dashboards/QualityMeasures'));
    }

    public function test_care_gaps_page_renders(): void
    {
        $u = $this->user();
        $this->actingAs($u);
        $this->get('/dashboards/gaps')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Dashboards/CareGaps'));
    }

    public function test_high_risk_page_renders(): void
    {
        $u = $this->user();
        $this->actingAs($u);
        $this->get('/dashboards/risk')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Dashboards/HighRisk'));
    }
}
