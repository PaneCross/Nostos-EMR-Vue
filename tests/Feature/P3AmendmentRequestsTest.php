<?php

// ─── Phase P3 — HIPAA §164.526 Right to Amend ──────────────────────────────
namespace Tests\Feature;

use App\Models\AmendmentRequest;
use App\Models\Participant;
use App\Models\ParticipantPortalUser;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class P3AmendmentRequestsTest extends TestCase
{
    use RefreshDatabase;

    private function setupTenant(): array
    {
        $t = Tenant::factory()->create();
        $prefix = strtoupper(\Illuminate\Support\Str::random(3));
        $site = Site::factory()->create(['tenant_id' => $t->id, 'mrn_prefix' => $prefix]);
        $p = Participant::factory()->enrolled()->forTenant($t->id)->forSite($site->id)->create();
        $u = User::factory()->create([
            'tenant_id' => $t->id, 'department' => 'qa_compliance',
            'role' => 'admin', 'is_active' => true,
        ]);
        $portalUser = ParticipantPortalUser::create([
            'tenant_id' => $t->id, 'participant_id' => $p->id,
            'email' => 'amendment@example.com', 'password' => Hash::make('x'),
            'is_active' => true,
        ]);
        return [$t, $u, $p, $portalUser];
    }

    public function test_portal_amendment_request_creates_amendment_row(): void
    {
        [$t, $u, $p, $portalUser] = $this->setupTenant();
        $r = $this->withHeader('X-Portal-User-Id', (string) $portalUser->id)
            ->postJson('/portal/requests', [
                'request_type' => 'amendment',
                'payload' => [
                    'target_field_or_section' => 'allergy list',
                    'requested_change' => 'Remove penicillin allergy — was misdiagnosed.',
                    'justification' => 'Allergist letter from 2025 confirms.',
                ],
            ]);
        $r->assertStatus(201);
        $this->assertEquals(1, AmendmentRequest::forTenant($t->id)->count());
        $a = AmendmentRequest::first();
        $this->assertEquals('pending', $a->status);
        $this->assertNotNull($a->deadline_at);
    }

    public function test_staff_can_accept(): void
    {
        [$t, $u, $p] = $this->setupTenant();
        $a = AmendmentRequest::create([
            'tenant_id' => $t->id, 'participant_id' => $p->id,
            'requested_change' => 'X', 'status' => 'pending',
            'deadline_at' => now()->addDays(60),
        ]);
        $this->actingAs($u);
        $this->postJson("/amendment-requests/{$a->id}/decide", ['status' => 'accepted'])
            ->assertOk();
        $this->assertEquals('accepted', $a->fresh()->status);
        $this->assertEquals($u->id, $a->fresh()->reviewer_user_id);
    }

    public function test_deny_requires_rationale(): void
    {
        [$t, $u, $p] = $this->setupTenant();
        $a = AmendmentRequest::create([
            'tenant_id' => $t->id, 'participant_id' => $p->id,
            'requested_change' => 'X', 'status' => 'pending',
            'deadline_at' => now()->addDays(60),
        ]);
        $this->actingAs($u);
        $this->postJson("/amendment-requests/{$a->id}/decide", ['status' => 'denied'])
            ->assertStatus(422);

        $this->postJson("/amendment-requests/{$a->id}/decide", [
            'status' => 'denied',
            'decision_rationale' => 'Information is accurate per signed clinical note.',
            'patient_disagreement_statement' => 'Patient still believes the entry is wrong.',
        ])->assertOk();
        $this->assertEquals('denied', $a->fresh()->status);
    }

    public function test_compliance_index_renders(): void
    {
        [$t, $u, $p] = $this->setupTenant();
        $this->actingAs($u);
        $this->get('/compliance/amendments')->assertOk()
            ->assertInertia(fn ($page) => $page->component('Compliance/AmendmentRequests'));
    }
}
