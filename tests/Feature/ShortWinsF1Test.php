<?php

namespace Tests\Feature;

use App\Models\EmarRecord;
use App\Models\GoalsOfCareConversation;
use App\Models\Immunization;
use App\Models\Medication;
use App\Models\Participant;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WoundPhoto;
use App\Models\WoundRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShortWinsF1Test extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private Site $site;
    private User $nurse;
    private Participant $participant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::factory()->create();
        $this->site = Site::factory()->create(['tenant_id' => $this->tenant->id, 'mrn_prefix' => 'F1']);
        $this->nurse = User::factory()->create(['tenant_id' => $this->tenant->id, 'department' => 'home_care', 'role' => 'admin', 'is_active' => true, 'site_id' => $this->site->id]);
        $this->participant = Participant::factory()->enrolled()->forTenant($this->tenant->id)->forSite($this->site->id)->create();
    }

    public function test_immunization_forecast_returns_due_in_30_days(): void
    {
        Immunization::create([
            'participant_id' => $this->participant->id, 'tenant_id' => $this->tenant->id,
            'vaccine_type' => 'influenza', 'vaccine_name' => 'Influenza', 'cvx_code' => '150',
            'administered_date' => now()->subYear(),
            'next_dose_due' => now()->addDays(15),
        ]);
        Immunization::create([
            'participant_id' => $this->participant->id, 'tenant_id' => $this->tenant->id,
            'vaccine_type' => 'pneumococcal_pcv20', 'vaccine_name' => 'Pneumo', 'cvx_code' => '133',
            'administered_date' => now()->subYears(5),
            'next_dose_due' => now()->addDays(90),
        ]);
        $this->actingAs($this->nurse);
        $r = $this->getJson('/widgets/immunization-forecast');
        $r->assertOk();
        $this->assertEquals(1, $r->json('count'));
    }

    public function test_late_dose_trend_counts_last_30_days(): void
    {
        $med = Medication::factory()->forParticipant($this->participant->id)->forTenant($this->tenant->id)
            ->create(['status' => 'active', 'is_controlled' => false, 'controlled_schedule' => null]);
        for ($i = 0; $i < 3; $i++) {
            EmarRecord::factory()->forParticipant($this->participant->id)->forTenant($this->tenant->id)
                ->create([
                    'medication_id' => $med->id,
                    'scheduled_time' => now()->subDays($i)->setTime(8, 0),
                    'status' => 'late',
                ]);
        }
        EmarRecord::factory()->forParticipant($this->participant->id)->forTenant($this->tenant->id)
            ->create([
                'medication_id' => $med->id,
                'scheduled_time' => now()->subDays(45)->setTime(8, 0), // outside 30d
                'status' => 'late',
            ]);
        $this->actingAs($this->nurse);
        $r = $this->getJson('/widgets/late-doses');
        $r->assertOk();
        $this->assertEquals(3, $r->json('total'));
    }

    public function test_wound_photos_crud(): void
    {
        $wound = WoundRecord::create([
            'participant_id' => $this->participant->id, 'tenant_id' => $this->tenant->id, 'site_id' => $this->site->id,
            'wound_type' => 'pressure_injury', 'location' => 'sacrum',
            'first_identified_date' => now()->subWeek(), 'status' => 'open',
            'documented_by_user_id' => $this->nurse->id,
        ]);
        $this->actingAs($this->nurse);
        $this->postJson("/wounds/{$wound->id}/photos", [
            'taken_at' => now()->toIso8601String(),
            'notes' => 'post-dressing change',
        ])->assertStatus(201);

        $r = $this->getJson("/wounds/{$wound->id}/photos");
        $r->assertOk();
        $this->assertCount(1, $r->json('photos'));
    }

    public function test_goals_of_care_conversation_records(): void
    {
        $this->actingAs($this->nurse);
        $this->postJson("/participants/{$this->participant->id}/goals-of-care", [
            'conversation_date' => now()->toDateString(),
            'participants_present' => 'Participant, daughter (POA), PCP',
            'discussion_summary' => 'Discussed preferences for ER transfers vs comfort care.',
            'decisions_made' => 'Comfort-focused for respiratory exacerbations; hospital OK for falls.',
            'next_steps' => 'Bring up at next IDT.',
        ])->assertStatus(201);
        $this->assertEquals(1, GoalsOfCareConversation::count());
    }

    public function test_goc_requires_summary_min_length(): void
    {
        $this->actingAs($this->nurse);
        $this->postJson("/participants/{$this->participant->id}/goals-of-care", [
            'conversation_date' => now()->toDateString(),
            'discussion_summary' => 'x',
        ])->assertStatus(422);
    }

    public function test_wound_photo_cross_tenant_blocked(): void
    {
        $other = Tenant::factory()->create();
        $oSite = Site::factory()->create(['tenant_id' => $other->id, 'mrn_prefix' => 'XX']);
        $otherP = Participant::factory()->enrolled()->forTenant($other->id)->forSite($oSite->id)->create();
        $wound = WoundRecord::create([
            'participant_id' => $otherP->id, 'tenant_id' => $other->id, 'site_id' => $oSite->id,
            'wound_type' => 'pressure_injury', 'location' => 'heel',
            'first_identified_date' => now(), 'status' => 'open',
            'documented_by_user_id' => $this->nurse->id,
        ]);
        $this->actingAs($this->nurse);
        $this->postJson("/wounds/{$wound->id}/photos", [
            'taken_at' => now()->toIso8601String(),
        ])->assertStatus(403);
    }
}
