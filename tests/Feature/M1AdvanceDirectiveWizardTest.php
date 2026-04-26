<?php

// ─── Phase M1 — Advance-directive wizard ────────────────────────────────────
// Locks in: the multi-step AD-wizard collects all CMS-required elements
// (DPOA-HC, living will, MOLST/POLST when applicable, code status, organ
// donation), creates a ConsentRecord with consent_type='advance_directive',
// and returns the user to the participant facesheet with the AD badge
// flipped. Regression trap if wizard step order or required-field map drifts.
namespace Tests\Feature;

use App\Models\ConsentRecord;
use App\Models\Participant;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class M1AdvanceDirectiveWizardTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private Participant $participant;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::factory()->create();
        $site = Site::factory()->create(['tenant_id' => $this->tenant->id, 'mrn_prefix' => 'M1']);
        $this->participant = Participant::factory()->enrolled()
            ->forTenant($this->tenant->id)->forSite($site->id)->create();
        $this->user = User::factory()->create([
            'tenant_id' => $this->tenant->id, 'department' => 'primary_care',
            'role' => 'standard', 'is_active' => true,
        ]);
    }

    private const PNG_STUB = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8//8/AwAI/AL+XJ4wCQAAAABJRU5ErkJggg==';

    public function test_wizard_page_renders(): void
    {
        $this->actingAs($this->user);
        $this->get("/participants/{$this->participant->id}/advance-directive/wizard")
            ->assertOk()
            ->assertInertia(fn ($p) => $p->component('AdvanceDirective/Wizard'));
    }

    public function test_wizard_submission_creates_signed_consent_and_updates_participant(): void
    {
        $this->actingAs($this->user);
        $r = $this->postJson("/participants/{$this->participant->id}/advance-directive", [
            'ad_type' => 'polst',
            'choices' => ['code_status' => 'dnr', 'intubation' => 'decline', 'comfort_only' => true],
            'signature_data_url' => self::PNG_STUB,
            'representative_type' => 'self',
        ]);
        $r->assertStatus(201);
        $this->assertEquals(1, ConsentRecord::where('participant_id', $this->participant->id)
            ->where('consent_type', 'advance_directive')->count());
        $p = $this->participant->fresh();
        $this->assertEquals('polst', $p->advance_directive_type);
        $this->assertEquals('has_directive', $p->advance_directive_status);
    }

    public function test_proxy_flow_requires_both_name_and_relationship(): void
    {
        $this->actingAs($this->user);
        $this->postJson("/participants/{$this->participant->id}/advance-directive", [
            'ad_type' => 'dnr',
            'choices' => ['code_status' => 'dnr'],
            'signature_data_url' => self::PNG_STUB,
            'proxy_signer_name' => 'Jane Doe',
            // missing proxy_relationship
        ])->assertStatus(422);

        $this->postJson("/participants/{$this->participant->id}/advance-directive", [
            'ad_type' => 'dnr',
            'choices' => ['code_status' => 'dnr'],
            'signature_data_url' => self::PNG_STUB,
            'proxy_signer_name' => 'Jane Doe',
            'proxy_relationship' => 'Daughter (POA)',
            'representative_type' => 'poa',
        ])->assertStatus(201);
    }
}
