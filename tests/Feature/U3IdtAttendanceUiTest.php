<?php

// ─── Phase U3 — IDT meeting attendance UI is wired ─────────────────────────
namespace Tests\Feature;

use App\Models\IdtMeeting;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class U3IdtAttendanceUiTest extends TestCase
{
    use RefreshDatabase;

    public function test_run_meeting_page_passes_tenant_users_prop(): void
    {
        $t = Tenant::factory()->create();
        $site = Site::factory()->create(['tenant_id' => $t->id, 'mrn_prefix' => 'AT']);
        $u = User::factory()->create([
            'tenant_id' => $t->id, 'site_id' => $site->id,
            'department' => 'idt', 'role' => 'admin', 'is_active' => true,
        ]);
        // Two more clinical users → roster of 3.
        User::factory()->count(2)->create([
            'tenant_id' => $t->id, 'department' => 'primary_care',
            'role' => 'standard', 'is_active' => true,
        ]);
        $m = IdtMeeting::create([
            'tenant_id' => $t->id, 'site_id' => $site->id,
            'meeting_date' => now()->toDateString(), 'meeting_time' => '09:00:00',
            'meeting_type' => 'daily', 'facilitator_user_id' => $u->id,
            'status' => 'in_progress', 'attendees' => [], 'revision' => 0,
        ]);

        $this->actingAs($u)
            ->get("/idt/meetings/{$m->id}")
            ->assertOk()
            ->assertInertia(fn ($p) => $p
                ->component('Idt/RunMeeting')
                ->has('tenant_users', 3)
            );
    }

    public function test_run_meeting_vue_renders_attendance_block(): void
    {
        $vue = file_get_contents(resource_path('js/Pages/Idt/RunMeeting.vue'));
        $this->assertStringContainsString('data-testid="idt-attendance"', $vue);
        $this->assertStringContainsString('/attendance', $vue);
        $this->assertStringContainsString('markAttendance', $vue);
    }
}
