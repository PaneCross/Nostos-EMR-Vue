<?php

// ─── Phase Q7 — Resolve 3 pre-existing TODOs ────────────────────────────────
namespace Tests\Feature;

use App\Models\Alert;
use App\Models\Appointment;
use App\Models\ChatChannel;
use App\Models\Participant;
use App\Models\Referral;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\User;
use App\Services\EnrollmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class Q7TodoCleanupTest extends TestCase
{
    use RefreshDatabase;

    public function test_no_show_emits_alert_to_transportation_and_enrollment(): void
    {
        $t = Tenant::factory()->create();
        $site = Site::factory()->create(['tenant_id' => $t->id, 'mrn_prefix' => 'NS']);
        $u = User::factory()->create([
            'tenant_id' => $t->id, 'site_id' => $site->id,
            'department' => 'primary_care', 'role' => 'admin', 'is_active' => true,
        ]);
        $p = Participant::factory()->enrolled()->forTenant($t->id)->forSite($site->id)->create();
        $a = Appointment::factory()->create([
            'tenant_id' => $t->id, 'participant_id' => $p->id,
            'status' => 'scheduled', 'scheduled_start' => now()->addHour(),
        ]);

        $this->actingAs($u)
            ->patchJson("/participants/{$p->id}/appointments/{$a->id}/no-show")
            ->assertOk();

        $alert = Alert::where('alert_type', 'appointment_no_show')->first();
        $this->assertNotNull($alert);
        $this->assertEqualsCanonicalizing(['transportation', 'enrollment'], $alert->target_departments);
    }

    public function test_referral_enrollment_creates_idt_chat_channel(): void
    {
        $t = Tenant::factory()->create();
        $site = Site::factory()->create(['tenant_id' => $t->id, 'mrn_prefix' => 'IDT']);
        $u = User::factory()->create([
            'tenant_id' => $t->id, 'site_id' => $site->id,
            'department' => 'enrollment', 'role' => 'admin', 'is_active' => true,
        ]);
        $p = Participant::factory()->enrolled()->forTenant($t->id)->forSite($site->id)->create();
        $r = Referral::factory()->create([
            'tenant_id' => $t->id, 'participant_id' => $p->id, 'status' => 'pending_enrollment',
        ]);

        app(EnrollmentService::class)->transition($r, 'enrolled', $u);

        $this->assertEquals(1, ChatChannel::where('participant_id', $p->id)
            ->where('channel_type', 'participant_idt')->count());
    }
}
