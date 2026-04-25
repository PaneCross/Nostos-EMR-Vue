<?php

// ─── Phase P2 — HIPAA §164.528 Accounting of Disclosures ───────────────────
namespace Tests\Feature;

use App\Models\Participant;
use App\Models\PhiDisclosure;
use App\Models\RoiRequest;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\User;
use App\Services\PhiDisclosureService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class P2PhiDisclosuresTest extends TestCase
{
    use RefreshDatabase;

    private function tenantUser(): array
    {
        $t = Tenant::factory()->create();
        // Random 3-letter prefix to avoid MRN collision across multiple tenants in one test run.
        $prefix = strtoupper(\Illuminate\Support\Str::random(3));
        $site = Site::factory()->create(['tenant_id' => $t->id, 'mrn_prefix' => $prefix]);
        $p = Participant::factory()->enrolled()->forTenant($t->id)->forSite($site->id)->create();
        $u = User::factory()->create([
            'tenant_id' => $t->id, 'department' => 'qa_compliance',
            'role' => 'admin', 'is_active' => true,
        ]);
        return [$t, $u, $p];
    }

    public function test_service_records_a_disclosure_row(): void
    {
        [$t, $u, $p] = $this->tenantUser();
        $svc = app(PhiDisclosureService::class);
        $row = $svc->record(
            tenantId: $t->id,
            participantId: $p->id,
            recipientType: 'insurer',
            recipientName: 'BCBS',
            purpose: 'Claim processing',
            method: 'paper',
            recordsDescribed: 'Office visit notes',
            disclosedByUserId: $u->id,
        );
        $this->assertInstanceOf(PhiDisclosure::class, $row);
        $this->assertEquals('insurer', $row->recipient_type);
    }

    public function test_roi_fulfilled_writes_a_disclosure_row(): void
    {
        [$t, $u, $p] = $this->tenantUser();
        $roi = RoiRequest::create([
            'tenant_id' => $t->id, 'participant_id' => $p->id,
            'requestor_type' => 'self',
            'requestor_name' => 'Patient Self',
            'requestor_contact' => 'patient@example.com',
            'records_requested_scope' => 'Last 6 months of clinical notes',
            'requested_at' => now(),
            'due_by' => now()->addDays(30),
            'status' => 'in_progress',
        ]);

        $this->actingAs($u);
        $this->postJson("/roi-requests/{$roi->id}/update-status", ['status' => 'fulfilled'])
            ->assertOk();

        $this->assertEquals(1, PhiDisclosure::forParticipant($p->id)->count());
        $d = PhiDisclosure::first();
        $this->assertEquals('patient_self', $d->recipient_type);
        $this->assertEquals('paper', $d->disclosure_method);
        $this->assertEquals(\App\Models\RoiRequest::class, $d->related_to_type);
    }

    public function test_per_participant_endpoint_returns_paginated_disclosures(): void
    {
        [$t, $u, $p] = $this->tenantUser();
        app(PhiDisclosureService::class)->record(
            tenantId: $t->id, participantId: $p->id,
            recipientType: 'public_health', recipientName: 'County DPH',
            purpose: 'Notifiable disease report', method: 'fax',
            recordsDescribed: 'TB positive lab + treatment plan',
            disclosedByUserId: $u->id,
        );
        $this->actingAs($u);
        $r = $this->getJson("/participants/{$p->id}/phi-disclosures");
        $r->assertOk();
        $this->assertGreaterThan(0, count($r->json('data')));
    }

    public function test_cross_tenant_disclosure_read_denied(): void
    {
        [$t1, $u1, $p1] = $this->tenantUser();
        [$t2, $u2, $p2] = $this->tenantUser();
        $this->actingAs($u1);
        $this->getJson("/participants/{$p2->id}/phi-disclosures")->assertForbidden();
    }
}
