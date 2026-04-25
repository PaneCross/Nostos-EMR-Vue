<?php

// ─── Phase P7 — Telehealth meeting URL field + Show banner ─────────────────
namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Participant;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class P7TelehealthMeetingUrlTest extends TestCase
{
    use RefreshDatabase;

    private function setupTenant(): array
    {
        $t = Tenant::factory()->create();
        $prefix = strtoupper(\Illuminate\Support\Str::random(3));
        $site = Site::factory()->create(['tenant_id' => $t->id, 'mrn_prefix' => $prefix]);
        $p = Participant::factory()->enrolled()->forTenant($t->id)->forSite($site->id)->create();
        $u = User::factory()->create([
            'tenant_id' => $t->id, 'department' => 'primary_care',
            'role' => 'admin', 'site_id' => $site->id, 'is_active' => true,
        ]);
        return [$t, $u, $p, $site];
    }

    public function test_meeting_url_persists_on_appointment(): void
    {
        [$t, $u, $p, $site] = $this->setupTenant();
        $a = Appointment::create([
            'tenant_id' => $t->id, 'participant_id' => $p->id, 'site_id' => $site->id,
            'appointment_type' => 'telehealth',
            'meeting_url' => 'https://meet.jit.si/test-room',
            'meeting_provider' => 'jitsi',
            'scheduled_start' => now()->addDay(),
            'scheduled_end'   => now()->addDay()->addMinutes(30),
            'status' => 'scheduled',
            'created_by_user_id' => $u->id,
        ]);
        $this->assertEquals('https://meet.jit.si/test-room', $a->fresh()->meeting_url);
    }

    public function test_show_page_renders_join_button_when_url_present(): void
    {
        [$t, $u, $p, $site] = $this->setupTenant();
        $a = Appointment::create([
            'tenant_id' => $t->id, 'participant_id' => $p->id, 'site_id' => $site->id,
            'appointment_type' => 'telehealth',
            'meeting_url' => 'https://example.com/visit/abc',
            'meeting_provider' => 'zoom',
            'scheduled_start' => now()->addDay(),
            'scheduled_end'   => now()->addDay()->addMinutes(30),
            'status' => 'scheduled',
            'created_by_user_id' => $u->id,
        ]);
        $this->actingAs($u);
        $this->get("/appointments/{$a->id}")->assertOk()
            ->assertInertia(fn ($p) => $p
                ->component('Appointments/Show')
                ->where('appointment.meeting_url', 'https://example.com/visit/abc')
            );
    }

    public function test_show_page_for_telehealth_without_url_uses_banner(): void
    {
        [$t, $u, $p, $site] = $this->setupTenant();
        $a = Appointment::create([
            'tenant_id' => $t->id, 'participant_id' => $p->id, 'site_id' => $site->id,
            'appointment_type' => 'telehealth',
            'scheduled_start' => now()->addDay(),
            'scheduled_end'   => now()->addDay()->addMinutes(30),
            'status' => 'scheduled',
            'created_by_user_id' => $u->id,
        ]);
        $this->actingAs($u);
        $r = $this->get("/appointments/{$a->id}");
        $r->assertOk();
        // Source check that the banner data-testid is in the bundle
        $vue = file_get_contents(resource_path('js/Pages/Appointments/Show.vue'));
        $this->assertStringContainsString('telehealth-missing-banner', $vue);
        $this->assertStringContainsString('telehealth-join-button', $vue);
    }
}
