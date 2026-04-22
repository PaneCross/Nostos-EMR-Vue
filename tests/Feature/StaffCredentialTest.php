<?php

// ─── StaffCredentialTest ──────────────────────────────────────────────────────
// Phase 4 (MVP roadmap) — §460.64-71 personnel credentialing.
// ─────────────────────────────────────────────────────────────────────────────

namespace Tests\Feature;

use App\Jobs\CredentialExpirationAlertJob;
use App\Models\Alert;
use App\Models\StaffCredential;
use App\Models\StaffTrainingRecord;
use App\Models\Tenant;
use App\Models\User;
use App\Services\AlertService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class StaffCredentialTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $itAdmin;
    private User $nurse;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::factory()->create();
        $this->itAdmin = User::factory()->create([
            'tenant_id'  => $this->tenant->id,
            'department' => 'it_admin',
            'role'       => 'admin',
            'is_active'  => true,
        ]);
        $this->nurse = User::factory()->create([
            'tenant_id'  => $this->tenant->id,
            'department' => 'primary_care',
            'role'       => 'standard',
            'is_active'  => true,
        ]);
    }

    private function credential(array $overrides = []): StaffCredential
    {
        return StaffCredential::create(array_merge([
            'tenant_id'       => $this->tenant->id,
            'user_id'         => $this->nurse->id,
            'credential_type' => 'license',
            'title'           => 'RN License',
            'issued_at'       => Carbon::now()->subYear()->toDateString(),
            'expires_at'      => Carbon::now()->addDays(30)->toDateString(),
        ], $overrides));
    }

    // ── Model helpers ────────────────────────────────────────────────────────

    public function test_status_bucket_computation(): void
    {
        $this->assertEquals('current', $this->credential(['expires_at' => Carbon::now()->addDays(120)->toDateString()])->status());
        $this->assertEquals('due_60',  $this->credential(['expires_at' => Carbon::now()->addDays(45)->toDateString()])->status());
        $this->assertEquals('due_30',  $this->credential(['expires_at' => Carbon::now()->addDays(20)->toDateString()])->status());
        $this->assertEquals('due_14',  $this->credential(['expires_at' => Carbon::now()->addDays(10)->toDateString()])->status());
        $this->assertEquals('expired', $this->credential(['expires_at' => Carbon::now()->subDays(5)->toDateString()])->status());
        $this->assertEquals('no_expiry', $this->credential(['expires_at' => null])->status());
    }

    // ── Alert job ────────────────────────────────────────────────────────────

    public function test_expiration_job_creates_alerts_at_thresholds(): void
    {
        Carbon::setTestNow('2026-06-01 08:00:00');
        // 30 days out
        $this->credential(['expires_at' => Carbon::parse('2026-07-01')->toDateString()]);
        // Expired 5 days ago
        $this->credential(['expires_at' => Carbon::parse('2026-05-27')->toDateString(), 'title' => 'BLS']);

        (new CredentialExpirationAlertJob())->handle(app(AlertService::class));

        $this->assertEquals(2, Alert::where('tenant_id', $this->tenant->id)->count());
        $this->assertTrue(Alert::where('alert_type', 'staff_credential_30d')->exists());
        $this->assertTrue(Alert::where('alert_type', 'staff_credential_expired')->exists());
    }

    public function test_job_dedupes_per_credential(): void
    {
        Carbon::setTestNow('2026-06-01');
        $this->credential(['expires_at' => Carbon::parse('2026-06-15')->toDateString()]);

        (new CredentialExpirationAlertJob())->handle(app(AlertService::class));
        (new CredentialExpirationAlertJob())->handle(app(AlertService::class));

        $this->assertEquals(1, Alert::where('alert_type', 'staff_credential_14d')->count());
    }

    // ── Controller: staff credentials page ───────────────────────────────────

    public function test_it_admin_can_view_user_credentials_page(): void
    {
        $this->credential();

        $this->actingAs($this->itAdmin)
            ->get("/it-admin/users/{$this->nurse->id}/credentials")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('credentials', 1)
                ->has('training')
                ->has('hoursByCategory')
            );
    }

    public function test_non_it_admin_cannot_view_credentials_page(): void
    {
        $random = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'department' => 'activities',
            'role' => 'standard',
            'is_active' => true,
        ]);

        $this->actingAs($random)
            ->get("/it-admin/users/{$this->nurse->id}/credentials")
            ->assertStatus(403);
    }

    public function test_can_add_credential_and_training(): void
    {
        $this->actingAs($this->itAdmin)
            ->postJson("/it-admin/users/{$this->nurse->id}/credentials", [
                'credential_type' => 'license',
                'title'           => 'RN License — CA',
                'license_state'   => 'CA',
                'license_number'  => 'RN-12345',
                'issued_at'       => '2025-01-01',
                'expires_at'      => '2027-01-01',
            ])
            ->assertStatus(201);

        $this->actingAs($this->itAdmin)
            ->postJson("/it-admin/users/{$this->nurse->id}/training", [
                'training_name'  => 'Annual HIPAA Refresher',
                'category'       => 'hipaa',
                'training_hours' => 1.0,
                'completed_at'   => '2026-01-15',
            ])
            ->assertStatus(201);

        $this->assertDatabaseHas('emr_staff_credentials', [
            'user_id' => $this->nurse->id,
            'title'   => 'RN License — CA',
        ]);
        $this->assertDatabaseHas('emr_staff_training_records', [
            'user_id'       => $this->nurse->id,
            'training_name' => 'Annual HIPAA Refresher',
        ]);
    }

    public function test_tenant_isolation_on_cross_tenant_credentials(): void
    {
        $otherTenant = Tenant::factory()->create();
        $otherUser = User::factory()->create([
            'tenant_id' => $otherTenant->id,
            'department' => 'primary_care',
            'role' => 'standard',
            'is_active' => true,
        ]);

        $this->actingAs($this->itAdmin)
            ->get("/it-admin/users/{$otherUser->id}/credentials")
            ->assertStatus(403);
    }

    // ── Dashboard widget ─────────────────────────────────────────────────────

    public function test_it_admin_dashboard_widget_returns_expiring(): void
    {
        $this->credential(['expires_at' => Carbon::now()->addDays(30)->toDateString()]);
        $this->credential(['expires_at' => Carbon::now()->subDays(5)->toDateString()]);
        // Far future — not returned
        $this->credential(['expires_at' => Carbon::now()->addDays(300)->toDateString()]);

        $this->actingAs($this->itAdmin)
            ->getJson('/dashboards/it-admin/expiring-credentials')
            ->assertOk()
            ->assertJsonStructure(['credentials', 'count_total', 'count_expired'])
            ->assertJsonPath('count_expired', 1);
    }

    // ── Audit universe ───────────────────────────────────────────────────────

    public function test_compliance_personnel_universe_returns_staff_and_credentials(): void
    {
        $this->credential();
        StaffTrainingRecord::create([
            'tenant_id'       => $this->tenant->id,
            'user_id'         => $this->nurse->id,
            'training_name'   => 'HIPAA',
            'category'        => 'hipaa',
            'training_hours'  => 1.0,
            'completed_at'    => Carbon::now()->subMonths(2)->toDateString(),
        ]);

        $this->actingAs($this->itAdmin)
            ->getJson('/compliance/personnel-credentials')
            ->assertOk()
            ->assertJsonStructure([
                'credentials' => [['id', 'user', 'credential_type', 'status']],
                'staff'       => [['id', 'name', 'training_hours_12mo_total']],
                'summary'     => ['credentials_total', 'credentials_due_60', 'active_staff_count'],
            ]);
    }

    public function test_unauthorized_dept_cannot_hit_audit_universe(): void
    {
        $random = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'department' => 'activities',
            'role' => 'standard',
            'is_active' => true,
        ]);

        $this->actingAs($random)
            ->getJson('/compliance/personnel-credentials')
            ->assertStatus(403);
    }
}
