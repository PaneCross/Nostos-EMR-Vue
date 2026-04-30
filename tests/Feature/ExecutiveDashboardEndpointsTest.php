<?php

// ─── ExecutiveDashboardEndpointsTest ─────────────────────────────────────────
// Smoke + contract tests for the 5 executive dashboard JSON endpoints.
//
// Coverage focus :
//   - siteComparison joins through participants (regression : an earlier cut
//     queried `emr_care_plans.site_id` which doesn't exist).
//   - deptCompliance returns the full {org_totals, departments[]} shape that
//     ExecutiveDashboard.vue expects (regression : the method was missing
//     entirely; the route 500'd with "Call to undefined method").
//   - 403 for non-executive / non-SA users.
//
// Tenant scoping is also exercised : each test creates a separate-tenant noise
// row to ensure the executive sees only their own tenant's data.
// ─────────────────────────────────────────────────────────────────────────────

namespace Tests\Feature;

use App\Models\Site;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ExecutiveDashboardEndpointsTest extends TestCase
{
    use RefreshDatabase;

    private function executive(): array
    {
        $tenant = Tenant::factory()->create();
        $site   = Site::factory()->create(['tenant_id' => $tenant->id, 'is_active' => true]);
        $user   = User::factory()->create([
            'tenant_id'  => $tenant->id,
            'site_id'    => $site->id,
            'department' => 'executive',
            'role'       => 'standard',
        ]);
        return compact('tenant', 'site', 'user');
    }

    public function test_site_comparison_returns_per_site_counts_via_participant_join(): void
    {
        ['user' => $user, 'site' => $site, 'tenant' => $tenant] = $this->executive();

        // Seed a participant + active care plan in this site → care plan should count.
        $participant = \App\Models\Participant::factory()->create([
            'tenant_id'         => $tenant->id,
            'site_id'           => $site->id,
            'enrollment_status' => 'enrolled',
        ]);
        DB::table('emr_care_plans')->insert([
            'tenant_id'      => $tenant->id,
            'participant_id' => $participant->id,
            'version'        => 1,
            'status'         => 'active',
            'created_at'     => now(),
            'updated_at'     => now(),
        ]);

        $this->actingAs($user)
            ->getJson('/dashboards/executive/site-comparison')
            ->assertOk()
            ->assertJsonStructure(['sites' => [['site_id', 'site_name', 'enrolled', 'active_care_plans']]])
            ->assertJsonPath('sites.0.active_care_plans', 1)
            ->assertJsonPath('sites.0.enrolled', 1);
    }

    public function test_dept_compliance_returns_org_totals_and_department_rows(): void
    {
        ['user' => $user] = $this->executive();

        $this->actingAs($user)
            ->getJson('/dashboards/executive/dept-compliance')
            ->assertOk()
            ->assertJsonStructure([
                'org_totals' => [
                    'overdue_sdrs', 'unsigned_notes', 'open_incidents',
                    'overdue_care_plans', 'overdue_idt_reviews',
                    'critical_wounds', 'hospitalizations_this_month',
                    'unacked_interactions',
                ],
                'departments' => [['department', 'label', 'score',
                    'overdue_sdrs', 'unsigned_notes', 'overdue_assessments',
                    'pending_orders', 'stat_orders']],
            ]);
    }

    public function test_dept_compliance_score_band_critical_when_overdue_sdr(): void
    {
        ['user' => $user, 'tenant' => $tenant] = $this->executive();
        $participant = \App\Models\Participant::factory()->create(['tenant_id' => $tenant->id]);

        // One overdue SDR for primary_care → that row should land 'critical'.
        DB::table('emr_sdrs')->insert([
            'tenant_id'             => $tenant->id,
            'participant_id'        => $participant->id,
            'requesting_department' => 'enrollment',
            'assigned_department'   => 'primary_care',
            'request_type'          => 'assessment_request',
            'description'           => 'test overdue',
            'priority'              => 'routine',
            'status'                => 'submitted',
            'submitted_at'          => now()->subDays(5),
            'due_at'                => now()->subDay(),
            'created_at'            => now(),
            'updated_at'            => now(),
        ]);

        $rows = $this->actingAs($user)
            ->getJson('/dashboards/executive/dept-compliance')
            ->assertOk()
            ->json('departments');

        $primaryCare = collect($rows)->firstWhere('department', 'primary_care');
        $this->assertSame(1, $primaryCare['overdue_sdrs']);
        $this->assertSame('critical', $primaryCare['score']);
    }

    public function test_regular_user_gets_403_on_dept_compliance(): void
    {
        $user = User::factory()->create(['department' => 'primary_care', 'role' => 'standard']);

        $this->actingAs($user)
            ->getJson('/dashboards/executive/dept-compliance')
            ->assertForbidden();
    }
}
