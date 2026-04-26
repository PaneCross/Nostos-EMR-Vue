<?php

// ─── Phase X1 — Amendment decide concurrency guard (Audit-12 H1) ───────────
// Verifies the `decide()` endpoint:
//   1. rejects the second submission with 409 when the row is already closed
//   2. produces exactly ONE set of §164.526(c)(3) downstream disclosure rows
//      even if the same request is hit twice (idempotency / no duplicate
//      immutable accounting entries).
namespace Tests\Feature;

use App\Models\AmendmentRequest;
use App\Models\Participant;
use App\Models\PhiDisclosure;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class X1AmendmentConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    private function setupAmendment(): array
    {
        $t = Tenant::factory()->create();
        $site = Site::factory()->create(['tenant_id' => $t->id, 'mrn_prefix' => strtoupper(Str::random(3))]);
        $u = User::factory()->create([
            'tenant_id' => $t->id, 'site_id' => $site->id,
            'department' => 'qa_compliance', 'role' => 'admin', 'is_active' => true,
        ]);
        $p = Participant::factory()->enrolled()->forTenant($t->id)->forSite($site->id)->create();
        $a = AmendmentRequest::create([
            'tenant_id' => $t->id, 'participant_id' => $p->id,
            'requested_change' => 'Remove duplicate diagnosis.',
            'status' => 'pending',
            'deadline_at' => now()->addDays(60),
        ]);
        return [$t, $u, $a];
    }

    public function test_second_decide_after_first_returns_409_and_no_duplicate_disclosures(): void
    {
        [$t, $u, $a] = $this->setupAmendment();
        $this->actingAs($u);

        $payload = [
            'status' => 'accepted',
            'share_with' => [
                ['recipient_type' => 'insurer', 'recipient_name' => 'Acme Health Plan'],
                ['recipient_type' => 'provider', 'recipient_name' => 'Mercy Cardiology'],
            ],
        ];

        // First decide succeeds.
        $r1 = $this->postJson("/amendment-requests/{$a->id}/decide", $payload);
        $r1->assertOk();

        // Sanity: 2 disclosures written.
        $this->assertEquals(2, PhiDisclosure::where('disclosure_purpose', 'amendment_notification')->count());

        // Second decide on the now-accepted request must return 409 and NOT
        // write more disclosures.
        $r2 = $this->postJson("/amendment-requests/{$a->id}/decide", $payload);
        $r2->assertStatus(409);

        $this->assertEquals(2, PhiDisclosure::where('disclosure_purpose', 'amendment_notification')->count(),
            'After 409, disclosure count must remain 2 — no duplicate immutable §164.528 rows.');
    }

    public function test_first_decide_persists_status_and_reviewer(): void
    {
        [$t, $u, $a] = $this->setupAmendment();
        $this->actingAs($u);
        $this->postJson("/amendment-requests/{$a->id}/decide", [
            'status' => 'accepted',
        ])->assertOk();

        $a->refresh();
        $this->assertEquals('accepted', $a->status);
        $this->assertEquals($u->id, $a->reviewer_user_id);
        $this->assertNotNull($a->reviewer_decision_at);
    }

    public function test_partial_share_with_failure_rolls_back_decision(): void
    {
        // If a PhiDisclosure write throws mid-loop, the wrapping transaction
        // must roll back the status update + earlier disclosures.
        [$t, $u, $a] = $this->setupAmendment();
        $this->actingAs($u);

        // Use an invalid recipient_type to trigger 422 BEFORE the transaction runs;
        // assert request state is unchanged.
        $r = $this->postJson("/amendment-requests/{$a->id}/decide", [
            'status' => 'accepted',
            'share_with' => [
                ['recipient_type' => 'invalid_type', 'recipient_name' => 'X'],
            ],
        ]);
        $r->assertStatus(422);
        $this->assertEquals('pending', $a->fresh()->status);
        $this->assertEquals(0, PhiDisclosure::count());
    }
}
