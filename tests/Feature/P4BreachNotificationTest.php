<?php

// ─── Phase P4 — HIPAA §164.404/§164.408 Breach Notification ────────────────
namespace Tests\Feature;

use App\Models\BreachIncident;
use App\Models\Tenant;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class P4BreachNotificationTest extends TestCase
{
    use RefreshDatabase;

    private function user(): User
    {
        $t = Tenant::factory()->create();
        return User::factory()->create([
            'tenant_id' => $t->id, 'department' => 'it_admin',
            'role' => 'admin', 'is_active' => true,
        ]);
    }

    public function test_log_a_breach_writes_row_with_60_day_deadline_for_500_plus(): void
    {
        $u = $this->user();
        $this->actingAs($u);
        $r = $this->postJson('/it-admin/breaches', [
            'discovered_at' => '2026-01-15',
            'affected_count' => 600,
            'breach_type' => 'hacking',
            'description' => 'Brute-force admin login attempt resulted in 600-record exfiltration.',
        ]);
        $r->assertStatus(201);
        $i = BreachIncident::first();
        $this->assertEquals(600, $i->affected_count);
        // 2026-01-15 + 60 days = 2026-03-16
        $this->assertEquals('2026-03-16', $i->hhs_deadline_at->toDateString());
    }

    public function test_log_breach_under_500_uses_march_1_following_year(): void
    {
        $u = $this->user();
        $this->actingAs($u);
        $this->postJson('/it-admin/breaches', [
            'discovered_at' => '2026-04-15',
            'affected_count' => 50,
            'breach_type' => 'lost_device',
            'description' => 'Encrypted laptop stolen from clinic with cached PHI of 50 participants.',
        ])->assertStatus(201);
        $i = BreachIncident::first();
        $this->assertEquals('2027-03-01', $i->hhs_deadline_at->toDateString());
    }

    public function test_compute_hhs_deadline_helper(): void
    {
        $discovered = Carbon::parse('2026-06-01');
        $this->assertEquals('2026-07-31', BreachIncident::computeHhsDeadline(500, $discovered)->toDateString());
        $this->assertEquals('2027-03-01', BreachIncident::computeHhsDeadline(499, $discovered)->toDateString());
    }

    public function test_index_renders_inertia_page(): void
    {
        $this->actingAs($this->user());
        $this->get('/it-admin/breaches')->assertOk()
            ->assertInertia(fn ($p) => $p->component('ItAdmin/BreachIncidents'));
    }
}
