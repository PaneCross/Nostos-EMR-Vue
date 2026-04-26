<?php

// ─── Phase Y2 — FormRequest messages() coverage on user-facing forms.
// W3 added CFR-aware messages on 5 critical forms; Y2 extends the same pattern
// to the high-traffic Vital / Medication / Allergy clinical entry forms.
// Smoke-tests the message keys actually surface (not Laravel-default strings).
namespace Tests\Feature;

use App\Models\Participant;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class Y2FormRequestMessagesTest extends TestCase
{
    use RefreshDatabase;

    private function authedClinician(): array
    {
        $t = Tenant::factory()->create();
        $s = Site::factory()->create(['tenant_id' => $t->id, 'mrn_prefix' => 'Y2A']);
        $u = User::factory()->create([
            'tenant_id' => $t->id, 'site_id' => $s->id,
            'department' => 'primary_care', 'role' => 'admin', 'is_active' => true,
        ]);
        $p = Participant::factory()->enrolled()->forTenant($t->id)->forSite($s->id)->create();
        return [$t, $u, $p];
    }

    public function test_vital_implausible_bp_returns_y2_message(): void
    {
        [, $u, $p] = $this->authedClinician();

        $r = $this->actingAs($u)
            ->post("/participants/{$p->id}/vitals", [
                'bp_systolic'  => 999,   // out of 40-300 range
                'bp_diastolic' => 80,
                'pulse'        => 70,
                'recorded_at'  => now()->toDateTimeString(),
            ]);
        $r->assertStatus(302);
        $errors = session('errors')->getBag('default')->get('bp_systolic');
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('plausible range', $errors[0]);
    }

    public function test_medication_invalid_route_returns_y2_message(): void
    {
        [, $u, $p] = $this->authedClinician();

        $r = $this->actingAs($u)
            ->post("/participants/{$p->id}/medications", [
                'drug_name'  => 'Lisinopril',
                'route'      => 'NOT_A_REAL_ROUTE',
                'start_date' => now()->toDateString(),
            ]);
        $r->assertStatus(302);
        $errors = session('errors')->getBag('default')->get('route');
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('Route must be one of', $errors[0]);
    }

    public function test_allergy_missing_severity_returns_y2_message(): void
    {
        [, $u, $p] = $this->authedClinician();

        $r = $this->actingAs($u)
            ->post("/participants/{$p->id}/allergies", [
                'allergy_type'  => 'medication',
                'allergen_name' => 'Penicillin',
                // severity omitted intentionally
            ]);
        $r->assertStatus(302);
        $errors = session('errors')->getBag('default')->get('severity');
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('banner alert', $errors[0]);
    }
}
