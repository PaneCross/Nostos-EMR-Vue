<?php

// ─── NotificationPreferenceServiceTest ────────────────────────────────────────
// Phase SS1 — exercises the per-tenant settings service that drives Site
// Settings + the optional alert routing across the EMR.
//
// Locks in:
//   - Required keys always return true regardless of stored state
//   - Required keys cannot be flipped off via set() / bulkSet()
//   - Optional/Reserved keys honor stored value, fall back to catalog default
//   - bulkSet() writes one AuditLog row per actually-changed preference
//   - seedDefaults() is idempotent + skips Required keys
//   - effectiveSettingsForTenant() returns merged catalog + tenant state
// ─────────────────────────────────────────────────────────────────────────────

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\NotificationPreference;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\User;
use App\Services\NotificationPreferenceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NotificationPreferenceServiceTest extends TestCase
{
    use RefreshDatabase;

    private function tenantWithUser(): array
    {
        $t = Tenant::factory()->create();
        $s = Site::factory()->create(['tenant_id' => $t->id, 'mrn_prefix' => 'NPS']);
        $u = User::factory()->create([
            'tenant_id' => $t->id, 'site_id' => $s->id,
            'department' => 'executive', 'role' => 'admin', 'is_active' => true,
        ]);
        return [$t, $u];
    }

    public function test_required_keys_always_return_true(): void
    {
        [$t] = $this->tenantWithUser();
        /** @var NotificationPreferenceService $svc */
        $svc = app(NotificationPreferenceService::class);

        // restraint observation overdue is REQUIRED — should always be true,
        // even with no row present and even after attempting to disable it.
        $this->assertTrue($svc->shouldNotify($t->id, 'designation.medical_director.restraint_observation_overdue'));
    }

    public function test_required_keys_cannot_be_disabled(): void
    {
        [$t, $u] = $this->tenantWithUser();
        $svc = app(NotificationPreferenceService::class);

        $svc->set($t->id, 'designation.medical_director.restraint_observation_overdue', false, $u->id);

        // The service silently no-ops; query directly to confirm no row was written.
        $row = NotificationPreference::where('tenant_id', $t->id)
            ->where('preference_key', 'designation.medical_director.restraint_observation_overdue')
            ->first();
        $this->assertNull($row);
        $this->assertTrue($svc->shouldNotify($t->id, 'designation.medical_director.restraint_observation_overdue'));
    }

    public function test_optional_key_default_off_and_flippable(): void
    {
        [$t, $u] = $this->tenantWithUser();
        $svc = app(NotificationPreferenceService::class);
        $key = 'designation.nursing_director.fall_risk_threshold';

        // Default OFF for nursing_director optional alerts
        $this->assertFalse($svc->shouldNotify($t->id, $key));

        $svc->set($t->id, $key, true, $u->id);
        $svc->clearCache($t->id);
        $this->assertTrue($svc->shouldNotify($t->id, $key));

        $svc->set($t->id, $key, false, $u->id);
        $svc->clearCache($t->id);
        $this->assertFalse($svc->shouldNotify($t->id, $key));
    }

    public function test_set_records_audit_log_only_on_real_change(): void
    {
        [$t, $u] = $this->tenantWithUser();
        $svc = app(NotificationPreferenceService::class);
        $key = 'designation.pharmacy_director.critical_drug_interaction';

        // First flip → audit log row
        $svc->set($t->id, $key, true, $u->id);
        $this->assertEquals(1, AuditLog::where('action', 'org_settings.preference_changed')->count());

        // Same-value set → no new audit log
        $svc->set($t->id, $key, true, $u->id);
        $this->assertEquals(1, AuditLog::where('action', 'org_settings.preference_changed')->count());

        // Real change → another row
        $svc->set($t->id, $key, false, $u->id);
        $this->assertEquals(2, AuditLog::where('action', 'org_settings.preference_changed')->count());
    }

    public function test_bulk_set_writes_one_audit_per_changed_key(): void
    {
        [$t, $u] = $this->tenantWithUser();
        $svc = app(NotificationPreferenceService::class);

        $changed = $svc->bulkSet($t->id, [
            'designation.nursing_director.fall_risk_threshold'           => true,
            'designation.nursing_director.pressure_injury_staging'       => true,
            'designation.medical_director.restraint_observation_overdue' => false, // REQUIRED — ignored
            'unknown.key.does.not.exist'                                  => true,  // ignored
        ], $u->id);

        $this->assertEquals(2, $changed);
        $this->assertEquals(2, AuditLog::where('action', 'org_settings.preference_changed')->count());
    }

    public function test_seed_defaults_is_idempotent(): void
    {
        [$t] = $this->tenantWithUser();
        $svc = app(NotificationPreferenceService::class);

        $first = $svc->seedDefaults($t->id);
        $this->assertGreaterThan(0, $first);

        // Second call should insert nothing — every non-required key already has a row.
        $second = $svc->seedDefaults($t->id);
        $this->assertEquals(0, $second);

        // No Required keys should ever land in the table.
        $stored = NotificationPreference::where('tenant_id', $t->id)->pluck('preference_key')->all();
        $catalog = NotificationPreferenceService::catalog();
        foreach ($stored as $k) {
            $this->assertNotEquals(NotificationPreferenceService::STATUS_REQUIRED, $catalog[$k]['status']);
        }
    }

    public function test_effective_settings_merges_catalog_with_stored_state(): void
    {
        [$t, $u] = $this->tenantWithUser();
        $svc = app(NotificationPreferenceService::class);
        $svc->set($t->id, 'designation.nursing_director.fall_risk_threshold', true, $u->id);

        $eff = $svc->effectiveSettingsForTenant($t->id);

        // Required → enabled regardless
        $this->assertTrue($eff['designation.medical_director.restraint_observation_overdue']['enabled']);
        // Optional flipped on → enabled
        $this->assertTrue($eff['designation.nursing_director.fall_risk_threshold']['enabled']);
        // Optional with no row + default off → disabled
        $this->assertFalse($eff['designation.nursing_director.pressure_injury_staging']['enabled']);

        // Catalog metadata travels through
        $this->assertArrayHasKey('group', $eff['designation.medical_director.restraint_observation_overdue']);
        $this->assertArrayHasKey('description', $eff['designation.nursing_director.fall_risk_threshold']);
    }

    public function test_catalog_entries_have_required_shape(): void
    {
        foreach (NotificationPreferenceService::catalog() as $key => $entry) {
            foreach (['group', 'label', 'description', 'status', 'default', 'wired'] as $field) {
                $this->assertArrayHasKey($field, $entry, "Catalog '$key' missing '$field'");
            }
            $this->assertContains($entry['status'], [
                NotificationPreferenceService::STATUS_REQUIRED,
                NotificationPreferenceService::STATUS_OPTIONAL,
                NotificationPreferenceService::STATUS_RESERVED,
            ]);
        }
    }
}
