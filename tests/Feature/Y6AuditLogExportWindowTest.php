<?php

// ─── Phase Y6 — AuditLog export 90-day default window (Audit-13 L8)
// Pre-Y6 the export endpoint did `->limit(10000)->get()` with no date
// filter, table-scanning shared_audit_logs on large tenants. Y6 adds a
// default 90-day window with optional ?from=&to= override.
namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class Y6AuditLogExportWindowTest extends TestCase
{
    use RefreshDatabase;

    private function authedItAdmin(): array
    {
        $t = Tenant::factory()->create();
        $u = User::factory()->create([
            'tenant_id' => $t->id, 'department' => 'it_admin',
            'role' => 'admin', 'is_active' => true,
        ]);
        return [$t, $u];
    }

    public function test_default_export_excludes_rows_older_than_90_days(): void
    {
        [$t, $u] = $this->authedItAdmin();

        // AuditLog is immutable — can't update created_at after create.
        // Insert directly via DB::table to control the timestamp.
        DB::table('shared_audit_logs')->insert([
            ['tenant_id' => $t->id, 'user_id' => $u->id, 'action' => 'fresh.row',
             'created_at' => now()->subDays(2)],
            ['tenant_id' => $t->id, 'user_id' => $u->id, 'action' => 'old.row',
             'created_at' => now()->subDays(120)],
        ]);

        $r = $this->actingAs($u)->get('/it-admin/audit/export');
        $r->assertOk();
        $body = $r->getContent();
        $this->assertStringContainsString('fresh.row', $body);
        $this->assertStringNotContainsString('old.row', $body);
    }

    public function test_explicit_window_override_widens_the_export(): void
    {
        [$t, $u] = $this->authedItAdmin();

        DB::table('shared_audit_logs')->insert([
            'tenant_id' => $t->id, 'user_id' => $u->id, 'action' => 'old.row',
            'created_at' => now()->subDays(180),
        ]);

        $from = now()->subDays(200)->toDateString();
        $to   = now()->toDateString();

        $r = $this->actingAs($u)->get("/it-admin/audit/export?from={$from}&to={$to}");
        $r->assertOk();
        $this->assertStringContainsString('old.row', $r->getContent());
    }

    public function test_filename_encodes_window(): void
    {
        [, $u] = $this->authedItAdmin();
        $r = $this->actingAs($u)->get('/it-admin/audit/export');
        $r->assertOk();
        $disp = $r->headers->get('Content-Disposition');
        $this->assertNotNull($disp);
        $this->assertMatchesRegularExpression(
            '/audit_log_\d{4}-\d{2}-\d{2}_to_\d{4}-\d{2}-\d{2}\.csv/',
            $disp,
        );
    }
}
