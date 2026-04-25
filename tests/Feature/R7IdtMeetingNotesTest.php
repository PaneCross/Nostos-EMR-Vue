<?php

// ─── Phase R7 — IDT meeting notes: revision guard + structured attendance ──
namespace Tests\Feature;

use App\Models\IdtMeeting;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class R7IdtMeetingNotesTest extends TestCase
{
    use RefreshDatabase;

    private function setupIdt(): array
    {
        $t = Tenant::factory()->create();
        $site = Site::factory()->create(['tenant_id' => $t->id, 'mrn_prefix' => 'IDT']);
        $u = User::factory()->create([
            'tenant_id' => $t->id, 'site_id' => $site->id,
            'department' => 'idt', 'role' => 'admin', 'is_active' => true,
        ]);
        $m = IdtMeeting::create([
            'tenant_id' => $t->id, 'site_id' => $site->id,
            'meeting_date' => now()->toDateString(),
            'meeting_time' => '09:00:00',
            'meeting_type' => 'daily',
            'facilitator_user_id' => $u->id,
            'attendees' => [],
            'status' => 'in_progress',
            'revision' => 0,
        ]);
        return [$t, $u, $m];
    }

    public function test_update_increments_revision(): void
    {
        [$t, $u, $m] = $this->setupIdt();
        $r = $this->actingAs($u)->patchJson("/idt/meetings/{$m->id}", [
            'minutes_text' => 'First draft.',
        ]);
        $r->assertOk();
        $this->assertEquals(1, $m->fresh()->revision);
        $this->assertNotNull($m->fresh()->last_edited_at);
    }

    public function test_concurrent_edit_returns_409(): void
    {
        [$t, $u, $m] = $this->setupIdt();
        // First user updates → revision becomes 1.
        $this->actingAs($u)->patchJson("/idt/meetings/{$m->id}", ['minutes_text' => 'A'])->assertOk();

        // Second user posts with stale expected_revision=0.
        $r = $this->actingAs($u)->patchJson("/idt/meetings/{$m->id}", [
            'minutes_text' => 'B (stale)',
            'expected_revision' => 0,
        ]);
        $r->assertStatus(409)->assertJsonPath('error', 'revision_conflict');
        $this->assertEquals('A', $m->fresh()->minutes_text);
    }

    public function test_record_attendance_stores_structured_status(): void
    {
        [$t, $u, $m] = $this->setupIdt();

        $r = $this->actingAs($u)->postJson("/idt/meetings/{$m->id}/attendance", [
            'user_id' => $u->id, 'status' => 'present',
        ]);
        $r->assertOk();

        $attendees = $m->fresh()->attendees;
        $this->assertEquals('present', $attendees[(string) $u->id]['status']);
        $this->assertNotNull($attendees[(string) $u->id]['recorded_at']);
    }
}
