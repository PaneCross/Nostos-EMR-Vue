<?php

// ─── Phase P6 — Prior Auth workflow ────────────────────────────────────────
namespace Tests\Feature;

use App\Models\Participant;
use App\Models\PriorAuthRequest;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class P6PriorAuthWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private function setupTenant(): array
    {
        $t = Tenant::factory()->create();
        $prefix = strtoupper(\Illuminate\Support\Str::random(3));
        $site = Site::factory()->create(['tenant_id' => $t->id, 'mrn_prefix' => $prefix]);
        $p = Participant::factory()->enrolled()->forTenant($t->id)->forSite($site->id)->create();
        $u = User::factory()->create([
            'tenant_id' => $t->id, 'department' => 'pharmacy',
            'role' => 'admin', 'is_active' => true,
        ]);
        return [$t, $u, $p];
    }

    public function test_create_pa_in_draft(): void
    {
        [$t, $u, $p] = $this->setupTenant();
        $this->actingAs($u);
        $r = $this->postJson("/participants/{$p->id}/prior-auth", [
            'related_to_type' => 'medication',
            'related_to_id' => 1,
            'payer_type' => 'medicare_d',
            'justification_text' => 'Non-formulary medication required for treatment of refractory hypertension.',
            'urgency' => 'standard',
        ]);
        $r->assertStatus(201);
        $this->assertEquals('draft', PriorAuthRequest::first()->status);
    }

    public function test_submit_then_approve(): void
    {
        [$t, $u, $p] = $this->setupTenant();
        $pa = PriorAuthRequest::create([
            'tenant_id' => $t->id, 'participant_id' => $p->id,
            'related_to_type' => 'medication', 'related_to_id' => 1,
            'payer_type' => 'medicare_d',
            'justification_text' => 'Refractory case.', 'urgency' => 'standard',
            'status' => 'draft', 'requested_by_user_id' => $u->id,
        ]);
        $this->actingAs($u);
        $this->postJson("/prior-auth/{$pa->id}/transition", ['status' => 'submitted'])->assertOk();
        $this->postJson("/prior-auth/{$pa->id}/transition", [
            'status' => 'approved',
            'approval_reference' => 'PA-12345',
            'expiration_date' => now()->addYear()->toDateString(),
        ])->assertOk();
        $f = $pa->fresh();
        $this->assertEquals('approved', $f->status);
        $this->assertEquals('PA-12345', $f->approval_reference);
    }

    public function test_deny_requires_rationale(): void
    {
        [$t, $u, $p] = $this->setupTenant();
        $pa = PriorAuthRequest::create([
            'tenant_id' => $t->id, 'participant_id' => $p->id,
            'related_to_type' => 'medication', 'related_to_id' => 1,
            'payer_type' => 'medicare_d',
            'justification_text' => 'X', 'urgency' => 'standard',
            'status' => 'submitted', 'requested_by_user_id' => $u->id,
        ]);
        $this->actingAs($u);
        $this->postJson("/prior-auth/{$pa->id}/transition", ['status' => 'denied'])
            ->assertStatus(422);
        $this->postJson("/prior-auth/{$pa->id}/transition", [
            'status' => 'denied',
            'decision_rationale' => 'Step-therapy not yet attempted per plan policy.',
        ])->assertOk();
    }

    public function test_invalid_transition_rejected(): void
    {
        [$t, $u, $p] = $this->setupTenant();
        $pa = PriorAuthRequest::create([
            'tenant_id' => $t->id, 'participant_id' => $p->id,
            'related_to_type' => 'medication', 'related_to_id' => 1,
            'payer_type' => 'medicare_d',
            'justification_text' => 'X', 'urgency' => 'standard',
            'status' => 'draft', 'requested_by_user_id' => $u->id,
        ]);
        $this->actingAs($u);
        $this->postJson("/prior-auth/{$pa->id}/transition", ['status' => 'approved'])
            ->assertStatus(422);
    }
}
