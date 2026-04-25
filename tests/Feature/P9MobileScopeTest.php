<?php

// ─── Phase P9 — Mobile day-list scope to assigned home_visit only ──────────
namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Participant;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class P9MobileScopeTest extends TestCase
{
    use RefreshDatabase;

    private function setupTenant(): array
    {
        $t = Tenant::factory()->create();
        $prefix = strtoupper(\Illuminate\Support\Str::random(3));
        $site = Site::factory()->create(['tenant_id' => $t->id, 'mrn_prefix' => $prefix]);
        $p = Participant::factory()->enrolled()->forTenant($t->id)->forSite($site->id)->create();
        $u = User::factory()->create([
            'tenant_id' => $t->id, 'department' => 'home_care',
            'role' => 'standard', 'site_id' => $site->id, 'is_active' => true,
        ]);
        return [$t, $u, $p, $site];
    }

    public function test_clinic_visit_excluded(): void
    {
        [$t, $u, $p, $site] = $this->setupTenant();
        Appointment::create([
            'tenant_id' => $t->id, 'participant_id' => $p->id, 'site_id' => $site->id,
            'appointment_type' => 'clinic_visit',
            'scheduled_start' => now()->setHour(10),
            'scheduled_end'   => now()->setHour(11),
            'status' => 'scheduled', 'provider_user_id' => $u->id,
            'created_by_user_id' => $u->id,
        ]);
        $this->actingAs($u);
        $r = $this->getJson('/mobile/today');
        $r->assertOk();
        $this->assertCount(0, $r->json('visits'));
    }

    public function test_unassigned_home_visit_excluded(): void
    {
        [$t, $u, $p, $site] = $this->setupTenant();
        Appointment::create([
            'tenant_id' => $t->id, 'participant_id' => $p->id, 'site_id' => $site->id,
            'appointment_type' => 'home_visit',
            'scheduled_start' => now()->setHour(14),
            'scheduled_end'   => now()->setHour(15),
            'status' => 'scheduled', 'provider_user_id' => null,
            'created_by_user_id' => $u->id,
        ]);
        $this->actingAs($u);
        $r = $this->getJson('/mobile/today');
        $r->assertOk();
        $this->assertCount(0, $r->json('visits'));
    }

    public function test_assigned_home_visit_included(): void
    {
        [$t, $u, $p, $site] = $this->setupTenant();
        $a = Appointment::create([
            'tenant_id' => $t->id, 'participant_id' => $p->id, 'site_id' => $site->id,
            'appointment_type' => 'home_visit',
            'scheduled_start' => now()->setHour(9),
            'scheduled_end'   => now()->setHour(10),
            'status' => 'scheduled', 'provider_user_id' => $u->id,
            'created_by_user_id' => $u->id,
        ]);
        $this->actingAs($u);
        $r = $this->getJson('/mobile/today');
        $r->assertOk();
        $visits = $r->json('visits');
        $this->assertCount(1, $visits);
        $this->assertEquals($a->id, $visits[0]['id']);
    }
}
