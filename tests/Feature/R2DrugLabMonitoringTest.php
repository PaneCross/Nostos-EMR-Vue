<?php

// ─── Phase R2 — DrugLabInteraction surfaced on prescribe ───────────────────
namespace Tests\Feature;

use App\Models\DrugLabInteraction;
use App\Models\Participant;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class R2DrugLabMonitoringTest extends TestCase
{
    use RefreshDatabase;

    private function setupRx(): array
    {
        $t = Tenant::factory()->create();
        $site = Site::factory()->create(['tenant_id' => $t->id, 'mrn_prefix' => 'DL']);
        $u = User::factory()->create([
            'tenant_id' => $t->id, 'site_id' => $site->id,
            'department' => 'primary_care', 'role' => 'standard', 'is_active' => true,
        ]);
        $p = Participant::factory()->enrolled()->forTenant($t->id)->forSite($site->id)->create();
        return [$t, $u, $p];
    }

    public function test_prescribing_warfarin_returns_inr_lab_monitoring_suggestion(): void
    {
        [$t, $u, $p] = $this->setupRx();
        DrugLabInteraction::create([
            'drug_keyword' => 'warfarin', 'lab_name' => 'INR',
            'loinc_code' => '6301-6', 'monitoring_frequency_days' => 30,
            'critical_low' => 1.5, 'critical_high' => 5.0, 'units' => 'ratio',
            'notes' => 'Target INR per anticoagulation plan.',
        ]);

        $r = $this->actingAs($u)
            ->postJson("/participants/{$p->id}/medications", [
                'drug_name' => 'Warfarin', 'dose' => 5, 'dose_unit' => 'mg',
                'route' => 'oral', 'frequency' => 'daily',
                'start_date' => now()->toDateString(), 'is_prn' => false,
            ])
            ->assertStatus(201);

        $r->assertJsonPath('lab_monitoring.0.lab_name', 'INR');
        $r->assertJsonPath('lab_monitoring.0.loinc_code', '6301-6');
        $r->assertJsonPath('lab_monitoring.0.every_days', 30);
    }

    public function test_unmatched_drug_returns_empty_lab_monitoring(): void
    {
        [$t, $u, $p] = $this->setupRx();
        DrugLabInteraction::create([
            'drug_keyword' => 'warfarin', 'lab_name' => 'INR',
            'loinc_code' => '6301-6', 'monitoring_frequency_days' => 30,
        ]);

        $r = $this->actingAs($u)
            ->postJson("/participants/{$p->id}/medications", [
                'drug_name' => 'Lisinopril', 'dose' => 10, 'dose_unit' => 'mg',
                'route' => 'oral', 'frequency' => 'daily',
                'start_date' => now()->toDateString(), 'is_prn' => false,
            ])
            ->assertStatus(201);

        $r->assertJsonPath('lab_monitoring', []);
    }
}
