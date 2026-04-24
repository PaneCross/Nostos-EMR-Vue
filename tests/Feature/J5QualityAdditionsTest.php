<?php

// ─── Phase J5 — Beers badge + Vitals critical-value ack + Wound photos ─────
namespace Tests\Feature;

use App\Models\Participant;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WoundRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class J5QualityAdditionsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private Participant $participant;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::factory()->create();
        $site = Site::factory()->create(['tenant_id' => $this->tenant->id, 'mrn_prefix' => 'J5']);
        $this->participant = Participant::factory()->enrolled()
            ->forTenant($this->tenant->id)->forSite($site->id)->create();
        $this->user = User::factory()->create([
            'tenant_id' => $this->tenant->id, 'department' => 'primary_care',
            'role' => 'standard', 'is_active' => true,
        ]);
    }

    public function test_beers_flags_endpoint_responds(): void
    {
        $this->actingAs($this->user);
        $this->getJson("/participants/{$this->participant->id}/beers-flags")
            ->assertOk()->assertJsonStructure(['flags']);
    }

    public function test_pending_critical_values_endpoint(): void
    {
        $this->actingAs($this->user);
        $r = $this->getJson("/participants/{$this->participant->id}/critical-values");
        $r->assertOk()->assertJsonStructure(['pending']);
    }

    public function test_wound_photos_index_and_store(): void
    {
        $wound = WoundRecord::create([
            'tenant_id' => $this->tenant->id,
            'site_id' => $this->participant->site_id,
            'participant_id' => $this->participant->id,
            'wound_type' => 'pressure_injury',
            'location' => 'sacrum',
            'status' => 'open',
            'first_identified_date' => now()->subWeek()->toDateString(),
            'documented_by_user_id' => $this->user->id,
        ]);
        $this->actingAs($this->user);

        $this->getJson("/wounds/{$wound->id}/photos")
            ->assertOk()->assertJsonStructure(['photos']);

        $r = $this->postJson("/wounds/{$wound->id}/photos", [
            'taken_at' => now()->toDateString(),
            'notes' => 'initial dressing change',
        ]);
        $this->assertContains($r->status(), [200, 201]);
    }
}
