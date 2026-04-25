<?php

// ─── Phase R6 — Day-center event-status snapshot + check-out + roster PDF ──
namespace Tests\Feature;

use App\Models\DayCenterAttendance;
use App\Models\Participant;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class R6DayCenterEventStatusTest extends TestCase
{
    use RefreshDatabase;

    private function setupDc(): array
    {
        $t = Tenant::factory()->create();
        $site = Site::factory()->create(['tenant_id' => $t->id, 'mrn_prefix' => 'DC']);
        $u = User::factory()->create([
            'tenant_id' => $t->id, 'site_id' => $site->id,
            'department' => 'activities', 'role' => 'admin', 'is_active' => true,
        ]);
        return [$t, $site, $u];
    }

    public function test_event_status_buckets_participants_correctly(): void
    {
        [$t, $site, $u] = $this->setupDc();
        $today = now()->toDateString();
        $weekday = strtolower(substr(now()->format('D'), 0, 3));

        // 4 participants all expected today.
        $sched = Participant::factory()->enrolled()->forTenant($t->id)->forSite($site->id)
            ->create(['day_center_days' => [$weekday]]);
        $arrived = Participant::factory()->enrolled()->forTenant($t->id)->forSite($site->id)
            ->create(['day_center_days' => [$weekday]]);
        $checkedOut = Participant::factory()->enrolled()->forTenant($t->id)->forSite($site->id)
            ->create(['day_center_days' => [$weekday]]);
        $absent = Participant::factory()->enrolled()->forTenant($t->id)->forSite($site->id)
            ->create(['day_center_days' => [$weekday]]);

        DayCenterAttendance::create([
            'tenant_id' => $t->id, 'site_id' => $site->id, 'participant_id' => $arrived->id,
            'attendance_date' => $today, 'status' => 'present', 'check_in_time' => '09:00:00',
            'recorded_by_user_id' => $u->id,
        ]);
        DayCenterAttendance::create([
            'tenant_id' => $t->id, 'site_id' => $site->id, 'participant_id' => $checkedOut->id,
            'attendance_date' => $today, 'status' => 'present',
            'check_in_time' => '09:05:00', 'check_out_time' => '15:00:00',
            'recorded_by_user_id' => $u->id,
        ]);
        DayCenterAttendance::create([
            'tenant_id' => $t->id, 'site_id' => $site->id, 'participant_id' => $absent->id,
            'attendance_date' => $today, 'status' => 'absent', 'absent_reason' => 'illness',
            'recorded_by_user_id' => $u->id,
        ]);

        $r = $this->actingAs($u)
            ->getJson("/scheduling/day-center/event-status?site_id={$site->id}");
        $r->assertOk()
          ->assertJsonPath('totals.scheduled', 1)
          ->assertJsonPath('totals.arrived', 1)
          ->assertJsonPath('totals.checked_out', 1)
          ->assertJsonPath('totals.absent_or_cancelled', 1)
          ->assertJsonPath('totals.expected', 4);
    }

    public function test_check_out_updates_record(): void
    {
        [$t, $site, $u] = $this->setupDc();
        $p = Participant::factory()->enrolled()->forTenant($t->id)->forSite($site->id)->create();
        DayCenterAttendance::create([
            'tenant_id' => $t->id, 'site_id' => $site->id, 'participant_id' => $p->id,
            'attendance_date' => now()->toDateString(), 'status' => 'present',
            'check_in_time' => '09:00:00', 'recorded_by_user_id' => $u->id,
        ]);

        $r = $this->actingAs($u)->postJson('/scheduling/day-center/check-out', [
            'participant_id' => $p->id, 'site_id' => $site->id,
            'attendance_date' => now()->toDateString(), 'check_out_time' => '15:30',
        ]);
        $r->assertOk();
        $this->assertStringStartsWith('15:30', (string) DayCenterAttendance::first()->check_out_time);
    }

    public function test_roster_pdf_renders(): void
    {
        [$t, $site, $u] = $this->setupDc();
        $weekday = strtolower(substr(now()->format('D'), 0, 3));
        Participant::factory()->enrolled()->forTenant($t->id)->forSite($site->id)
            ->create(['day_center_days' => [$weekday]]);

        $r = $this->actingAs($u)->get("/scheduling/day-center/roster.pdf?site_id={$site->id}");
        $r->assertOk();
        $this->assertEquals('application/pdf', $r->headers->get('content-type'));
    }
}
