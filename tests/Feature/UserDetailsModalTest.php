<?php

// ─── UserDetailsModalTest ─────────────────────────────────────────────────────
// Covers GET /it-admin/users/{user}/details — the JSON endpoint feeding the
// user-detail modal on the IT Admin Users page.
//
// Assertions:
//   - IT Admin gate enforced (non-it_admin returns 403)
//   - Cross-tenant access returns 403
//   - Response shape includes: user / credentials / training / activity
//   - Activity feed FILTERS pure-read actions (*.viewed, global.search,
//     participant.searched, portal.view_*, fhir.read.*, qapi_annual_evaluation.reviewed)
//   - Activity feed INCLUDES data-mutating actions (*.created, *.updated, etc.)
// ─────────────────────────────────────────────────────────────────────────────

namespace Tests\Feature;

use App\Models\Site;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class UserDetailsModalTest extends TestCase
{
    use RefreshDatabase;

    private function tenantWithIt(): array
    {
        $t = Tenant::factory()->create();
        $s = Site::factory()->create(['tenant_id' => $t->id, 'mrn_prefix' => 'UDM']);
        $itAdmin = User::factory()->create([
            'tenant_id' => $t->id, 'site_id' => $s->id,
            'department' => 'it_admin', 'role' => 'admin', 'is_active' => true,
        ]);
        return [$t, $s, $itAdmin];
    }

    public function test_endpoint_requires_it_admin(): void
    {
        [$t, $s] = $this->tenantWithIt();
        $pcp = User::factory()->create([
            'tenant_id' => $t->id, 'site_id' => $s->id,
            'department' => 'primary_care', 'role' => 'admin', 'is_active' => true,
        ]);
        $target = User::factory()->create([
            'tenant_id' => $t->id, 'site_id' => $s->id,
            'department' => 'pharmacy', 'role' => 'standard', 'is_active' => true,
        ]);

        $r = $this->actingAs($pcp)->getJson("/it-admin/users/{$target->id}/details");
        $r->assertStatus(403);
    }

    public function test_endpoint_blocks_cross_tenant(): void
    {
        [, , $itAdmin] = $this->tenantWithIt();

        // A user in a DIFFERENT tenant
        $otherT = Tenant::factory()->create();
        $otherSite = Site::factory()->create(['tenant_id' => $otherT->id, 'mrn_prefix' => 'XOX']);
        $otherUser = User::factory()->create([
            'tenant_id' => $otherT->id, 'site_id' => $otherSite->id,
            'department' => 'primary_care', 'role' => 'admin', 'is_active' => true,
        ]);

        $r = $this->actingAs($itAdmin)->getJson("/it-admin/users/{$otherUser->id}/details");
        $r->assertStatus(403);
    }

    public function test_endpoint_returns_expected_shape(): void
    {
        [$t, $s, $itAdmin] = $this->tenantWithIt();
        $target = User::factory()->create([
            'tenant_id' => $t->id, 'site_id' => $s->id,
            'department' => 'pharmacy', 'role' => 'standard', 'is_active' => true,
        ]);

        $r = $this->actingAs($itAdmin)->getJson("/it-admin/users/{$target->id}/details");
        $r->assertOk()
            ->assertJsonStructure([
                'user' => [
                    'id', 'first_name', 'last_name', 'email', 'department', 'role',
                    'is_active', 'designations', 'site',
                    'last_login_at', 'failed_login_attempts', 'locked_until',
                    'provisioned_at', 'provisioned_by', 'created_at',
                ],
                'credentials' => ['active_count', 'expiring_count', 'expired_count', 'total_count'],
                'training'    => ['total_hours_12mo', 'by_category'],
                'activity'    => ['count_30_days', 'count_90_days', 'top_actions', 'recent'],
            ]);
        $this->assertEquals($target->id, $r->json('user.id'));
    }

    public function test_activity_feed_excludes_view_and_search_actions(): void
    {
        [$t, $s, $itAdmin] = $this->tenantWithIt();
        $target = User::factory()->create([
            'tenant_id' => $t->id, 'site_id' => $s->id,
            'department' => 'pharmacy', 'role' => 'standard', 'is_active' => true,
        ]);

        // Insert a mix: 4 read-only actions (should be excluded) + 3 mutations (should appear).
        // AuditLog is immutable so we use direct DB inserts to control timestamps.
        $rows = [
            ['action' => 'participant.profile.viewed',         'when' => now()->subDays(1)],
            ['action' => 'global.search',                       'when' => now()->subDays(2)],
            ['action' => 'participant.searched',                'when' => now()->subDays(3)],
            ['action' => 'portal.view_overview',                'when' => now()->subDays(4)],
            ['action' => 'fhir.read.Patient',                   'when' => now()->subDays(5)],
            ['action' => 'qapi_annual_evaluation.reviewed',     'when' => now()->subDays(6)],
            // Mutations (KEEP):
            ['action' => 'medication.created',                  'when' => now()->subDays(2)],
            ['action' => 'allergy.updated',                     'when' => now()->subHour()],
            ['action' => 'consent.signed',                      'when' => now()->subDays(10)],
        ];
        foreach ($rows as $row) {
            DB::table('shared_audit_logs')->insert([
                'tenant_id'  => $t->id,
                'user_id'    => $target->id,
                'action'     => $row['action'],
                'created_at' => $row['when'],
            ]);
        }

        $r = $this->actingAs($itAdmin)->getJson("/it-admin/users/{$target->id}/details");
        $r->assertOk();

        $recentActions = collect($r->json('activity.recent'))->pluck('action')->all();

        // Mutations present
        $this->assertContains('medication.created', $recentActions);
        $this->assertContains('allergy.updated', $recentActions);
        $this->assertContains('consent.signed', $recentActions);

        // Read-only filtered out
        $this->assertNotContains('participant.profile.viewed', $recentActions);
        $this->assertNotContains('global.search', $recentActions);
        $this->assertNotContains('participant.searched', $recentActions);
        $this->assertNotContains('portal.view_overview', $recentActions);
        $this->assertNotContains('fhir.read.Patient', $recentActions);
        $this->assertNotContains('qapi_annual_evaluation.reviewed', $recentActions);

        // Counts reflect only mutations
        $this->assertEquals(3, $r->json('activity.count_90_days'));
    }

    public function test_designation_details_const_covers_every_designation_with_full_shape(): void
    {
        // Every designation in DESIGNATIONS must have a corresponding DESIGNATION_DETAILS
        // entry with the four required keys. If a new designation is added without an
        // entry, the IT-admin Users modal would silently render no help text for it.
        foreach (\App\Models\User::DESIGNATIONS as $key) {
            $this->assertArrayHasKey($key, \App\Models\User::DESIGNATION_DETAILS,
                "DESIGNATION_DETAILS missing entry for '$key'");
            $entry = \App\Models\User::DESIGNATION_DETAILS[$key];
            foreach (['label', 'summary', 'permissions', 'notifications', 'reserved'] as $field) {
                $this->assertArrayHasKey($field, $entry,
                    "DESIGNATION_DETAILS['$key'] missing required field '$field'");
            }
            $this->assertIsString($entry['label']);
            $this->assertIsString($entry['summary']);
            $this->assertIsArray($entry['permissions']);
            $this->assertIsArray($entry['notifications']);
            $this->assertIsArray($entry['reserved']);
        }
    }

    public function test_users_index_inertia_includes_designation_details(): void
    {
        [, , $itAdmin] = $this->tenantWithIt();

        $r = $this->actingAs($itAdmin)->get('/it-admin/users');
        $r->assertOk();

        $r->assertInertia(fn ($page) => $page
            ->component('ItAdmin/Users')
            ->has('designationDetails')
            ->has('designationDetails.medical_director.permissions')
            ->has('designationDetails.medical_director.notifications')
            ->has('designationDetails.medical_director.reserved')
        );
    }

    public function test_credentials_summary_shape_returns_zero_when_user_has_none(): void
    {
        [$t, $s, $itAdmin] = $this->tenantWithIt();
        $target = User::factory()->create([
            'tenant_id' => $t->id, 'site_id' => $s->id,
            'department' => 'pharmacy', 'role' => 'standard', 'is_active' => true,
        ]);

        $r = $this->actingAs($itAdmin)->getJson("/it-admin/users/{$target->id}/details");
        $r->assertOk();
        $this->assertEquals(0, $r->json('credentials.total_count'));
        $this->assertEquals(0.0, (float) $r->json('training.total_hours_12mo'));
    }
}
