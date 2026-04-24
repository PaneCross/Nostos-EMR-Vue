<?php

namespace Tests\Feature;

use App\Jobs\AdeMedwatchReminderJob;
use App\Models\AdverseDrugEvent;
use App\Models\Alert;
use App\Models\Allergy;
use App\Models\Medication;
use App\Models\Participant;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdverseDrugEventTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private Site $site;
    private User $pharm;
    private Participant $participant;
    private Medication $med;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::factory()->create();
        $this->site = Site::factory()->create(['tenant_id' => $this->tenant->id, 'mrn_prefix' => 'AE']);
        $this->pharm = User::factory()->create([
            'tenant_id' => $this->tenant->id, 'department' => 'pharmacy',
            'role' => 'admin', 'is_active' => true, 'site_id' => $this->site->id,
        ]);
        $this->participant = Participant::factory()->enrolled()
            ->forTenant($this->tenant->id)->forSite($this->site->id)->create();
        $this->med = Medication::factory()
            ->forParticipant($this->participant->id)
            ->forTenant($this->tenant->id)
            ->create(['drug_name' => 'Vancomycin', 'status' => 'active', 'is_controlled' => false, 'controlled_schedule' => null]);
    }

    public function test_moderate_event_does_not_create_allergy(): void
    {
        $this->actingAs($this->pharm);
        $this->postJson("/participants/{$this->participant->id}/ade", [
            'medication_id' => $this->med->id,
            'onset_date' => now()->subDay()->toDateString(),
            'severity' => 'moderate',
            'causality' => 'probable',
            'reaction_description' => 'Nausea and rash after second dose.',
        ])->assertStatus(201);
        $this->assertEquals(0, Allergy::count());
    }

    public function test_severe_event_auto_creates_allergy(): void
    {
        $this->actingAs($this->pharm);
        $this->postJson("/participants/{$this->participant->id}/ade", [
            'medication_id' => $this->med->id,
            'onset_date' => now()->subDay()->toDateString(),
            'severity' => 'severe',
            'causality' => 'probable',
            'reaction_description' => 'Acute kidney injury after IV dose.',
        ])->assertStatus(201);
        $this->assertTrue(Allergy::where('allergen_name', 'Vancomycin')->exists());
        $this->assertTrue(AdverseDrugEvent::first()->auto_allergy_created);
    }

    public function test_duplicate_allergy_not_created(): void
    {
        Allergy::create([
            'tenant_id' => $this->tenant->id, 'participant_id' => $this->participant->id,
            'allergy_type' => 'drug', 'allergen_name' => 'Vancomycin',
            'reaction_description' => 'Prior', 'severity' => 'moderate',
            'onset_date' => now()->subYear(), 'is_active' => true,
        ]);
        $this->actingAs($this->pharm);
        $this->postJson("/participants/{$this->participant->id}/ade", [
            'medication_id' => $this->med->id,
            'onset_date' => now()->toDateString(),
            'severity' => 'severe', 'causality' => 'probable',
            'reaction_description' => 'Acute kidney injury',
        ])->assertStatus(201);
        $this->assertEquals(1, Allergy::where('allergen_name', 'Vancomycin')->count());
        $this->assertFalse(AdverseDrugEvent::first()->auto_allergy_created);
    }

    public function test_medwatch_reminder_job_fires_warning_before_deadline(): void
    {
        AdverseDrugEvent::create([
            'tenant_id' => $this->tenant->id, 'participant_id' => $this->participant->id,
            'medication_id' => $this->med->id,
            'onset_date' => now()->subDays(5),
            'severity' => 'severe', 'causality' => 'probable',
            'reaction_description' => 'x', 'reporter_user_id' => $this->pharm->id,
        ]);
        (new AdeMedwatchReminderJob())->handle(app(\App\Services\AlertService::class));
        $this->assertTrue(Alert::where('alert_type', 'ade_medwatch_reminder')->exists());
    }

    public function test_medwatch_reminder_job_fires_critical_after_deadline(): void
    {
        AdverseDrugEvent::create([
            'tenant_id' => $this->tenant->id, 'participant_id' => $this->participant->id,
            'medication_id' => $this->med->id,
            'onset_date' => now()->subDays(20),
            'severity' => 'life_threatening', 'causality' => 'definite',
            'reaction_description' => 'x', 'reporter_user_id' => $this->pharm->id,
        ]);
        (new AdeMedwatchReminderJob())->handle(app(\App\Services\AlertService::class));
        $alert = Alert::where('alert_type', 'ade_medwatch_overdue')->first();
        $this->assertNotNull($alert);
        $this->assertEquals('critical', $alert->severity);
    }

    public function test_mark_reported_updates_tracking(): void
    {
        $ade = AdverseDrugEvent::create([
            'tenant_id' => $this->tenant->id, 'participant_id' => $this->participant->id,
            'medication_id' => $this->med->id,
            'onset_date' => now()->subDays(5),
            'severity' => 'severe', 'causality' => 'probable',
            'reaction_description' => 'x', 'reporter_user_id' => $this->pharm->id,
        ]);
        $this->actingAs($this->pharm);
        $this->postJson("/ade/{$ade->id}/mark-reported", [
            'medwatch_tracking_number' => 'FDA-12345',
        ])->assertOk();
        $ade->refresh();
        $this->assertNotNull($ade->reported_to_medwatch_at);
        $this->assertEquals('FDA-12345', $ade->medwatch_tracking_number);
    }

    public function test_mark_reported_rejects_mild_events(): void
    {
        $ade = AdverseDrugEvent::create([
            'tenant_id' => $this->tenant->id, 'participant_id' => $this->participant->id,
            'medication_id' => $this->med->id,
            'onset_date' => now()->subDays(2),
            'severity' => 'mild', 'causality' => 'possible',
            'reaction_description' => 'x', 'reporter_user_id' => $this->pharm->id,
        ]);
        $this->actingAs($this->pharm);
        $this->postJson("/ade/{$ade->id}/mark-reported", [
            'medwatch_tracking_number' => 'FDA-1',
        ])->assertStatus(422);
    }
}
