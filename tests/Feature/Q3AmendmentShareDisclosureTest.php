<?php

// ─── Phase Q3 — Amendment accept → §164.526(c)(3) downstream notification ──
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

class Q3AmendmentShareDisclosureTest extends TestCase
{
    use RefreshDatabase;

    private function setup3(): array
    {
        $t = Tenant::factory()->create();
        $prefix = strtoupper(Str::random(3));
        $site = Site::factory()->create(['tenant_id' => $t->id, 'mrn_prefix' => $prefix]);
        $p = Participant::factory()->enrolled()->forTenant($t->id)->forSite($site->id)->create();
        $u = User::factory()->create([
            'tenant_id' => $t->id, 'department' => 'qa_compliance',
            'role' => 'admin', 'is_active' => true,
        ]);
        return [$t, $u, $p];
    }

    public function test_accept_with_share_with_records_phi_disclosures(): void
    {
        [$t, $u, $p] = $this->setup3();
        $a = AmendmentRequest::create([
            'tenant_id' => $t->id, 'participant_id' => $p->id,
            'requested_change' => 'Remove duplicate diagnosis.',
            'status' => 'pending',
            'deadline_at' => now()->addDays(60),
        ]);
        $this->actingAs($u);
        $this->postJson("/amendment-requests/{$a->id}/decide", [
            'status' => 'accepted',
            'share_with' => [
                ['recipient_type' => 'insurer', 'recipient_name' => 'Acme Health Plan', 'recipient_contact' => 'fax 555-1212'],
                ['recipient_type' => 'provider', 'recipient_name' => 'Mercy Cardiology Clinic'],
            ],
        ])->assertOk();

        $this->assertEquals(2, PhiDisclosure::where('participant_id', $p->id)
            ->where('disclosure_purpose', 'amendment_notification')->count());
    }

    public function test_accept_without_share_with_records_no_disclosures(): void
    {
        [$t, $u, $p] = $this->setup3();
        $a = AmendmentRequest::create([
            'tenant_id' => $t->id, 'participant_id' => $p->id,
            'requested_change' => 'Y', 'status' => 'pending',
            'deadline_at' => now()->addDays(60),
        ]);
        $this->actingAs($u);
        $this->postJson("/amendment-requests/{$a->id}/decide", ['status' => 'accepted'])
            ->assertOk();

        $this->assertEquals(0, PhiDisclosure::where('participant_id', $p->id)->count());
    }

    public function test_deny_ignores_share_with(): void
    {
        [$t, $u, $p] = $this->setup3();
        $a = AmendmentRequest::create([
            'tenant_id' => $t->id, 'participant_id' => $p->id,
            'requested_change' => 'Z', 'status' => 'pending',
            'deadline_at' => now()->addDays(60),
        ]);
        $this->actingAs($u);
        $this->postJson("/amendment-requests/{$a->id}/decide", [
            'status' => 'denied',
            'decision_rationale' => 'Information is accurate.',
            'share_with' => [
                ['recipient_type' => 'insurer', 'recipient_name' => 'Acme'],
            ],
        ])->assertOk();

        $this->assertEquals(0, PhiDisclosure::count());
    }
}
