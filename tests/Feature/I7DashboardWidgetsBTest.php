<?php

// ─── Phase I7 — QA + Home Care + Dietary dashboard widgets ─────────────────
namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class I7DashboardWidgetsBTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::factory()->create();
    }

    private function user(string $dept): User
    {
        return User::factory()->create([
            'tenant_id' => $this->tenant->id, 'department' => $dept,
            'role' => 'standard', 'is_active' => true,
        ]);
    }

    public function test_qa_widgets_return_expected_shape(): void
    {
        $u = $this->user('qa_compliance');
        $this->actingAs($u);

        $this->getJson('/dashboards/qa-compliance/sentinel-rollup')
            ->assertOk()->assertJsonStructure(['rows', 'total']);
        $this->getJson('/dashboards/qa-compliance/critical-values-pending')
            ->assertOk()->assertJsonStructure(['rows', 'total']);
        $this->getJson('/dashboards/qa-compliance/roi-due-soon')
            ->assertOk()->assertJsonStructure(['rows', 'total']);
        $this->getJson('/dashboards/qa-compliance/tb-overdue')
            ->assertOk()->assertJsonStructure(['overdue_count', 'due_soon_count']);
    }

    public function test_qa_widgets_reject_wrong_dept(): void
    {
        $u = $this->user('dietary');
        $this->actingAs($u);
        $this->getJson('/dashboards/qa-compliance/sentinel-rollup')->assertForbidden();
        $this->getJson('/dashboards/qa-compliance/tb-overdue')->assertForbidden();
    }

    public function test_home_care_widgets_return_expected_shape(): void
    {
        $u = $this->user('home_care');
        $this->actingAs($u);

        $this->getJson('/dashboards/home-care/restraint-overdue')
            ->assertOk()->assertJsonStructure(['rows', 'total']);
        $this->getJson('/dashboards/home-care/active-infections')
            ->assertOk()->assertJsonStructure(['rows', 'total']);
        $this->getJson('/dashboards/home-care/high-risk-caseload')
            ->assertOk()->assertJsonStructure(['rows', 'total']);
    }

    public function test_home_care_widgets_reject_wrong_dept(): void
    {
        $u = $this->user('dietary');
        $this->actingAs($u);
        $this->getJson('/dashboards/home-care/restraint-overdue')->assertForbidden();
        $this->getJson('/dashboards/home-care/active-infections')->assertForbidden();
        $this->getJson('/dashboards/home-care/high-risk-caseload')->assertForbidden();
    }

    public function test_dietary_widgets_return_expected_shape(): void
    {
        $u = $this->user('dietary');
        $this->actingAs($u);

        $this->getJson('/dashboards/dietary/orders-by-type')
            ->assertOk()->assertJsonStructure(['rows', 'total']);
        $this->getJson('/dashboards/dietary/iadl-food-prep-candidates')
            ->assertOk()->assertJsonStructure(['rows', 'total']);
    }

    public function test_dietary_widgets_reject_wrong_dept(): void
    {
        $u = $this->user('pharmacy');
        $this->actingAs($u);
        $this->getJson('/dashboards/dietary/orders-by-type')->assertForbidden();
        $this->getJson('/dashboards/dietary/iadl-food-prep-candidates')->assertForbidden();
    }
}
