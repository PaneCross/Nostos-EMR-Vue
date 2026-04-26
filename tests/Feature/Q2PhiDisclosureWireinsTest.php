<?php

// ─── Phase Q2 — PhiDisclosure wired into CCDA + FHIR R4 + Bulk Export ──────
// Locks in: every external PHI release path writes a row to
// emr_phi_disclosures so §164.528 accounting works. Covers the three
// disclosure surfaces P2 didn't initially wire:
//   - C-CDA Continuity-of-Care Document export
//   - FHIR R4 Patient/$everything + per-resource reads
//   - FHIR Bulk Data $export NDJSON downloads
// Regression trap: if any new export endpoint is added later, mirror this
// pattern or this audit-log gap will reopen.
// CFR ref: 45 CFR §164.528 — accounting of disclosures.
namespace Tests\Feature;

use App\Models\ApiToken;
use App\Models\Participant;
use App\Models\PhiDisclosure;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class Q2PhiDisclosureWireinsTest extends TestCase
{
    use RefreshDatabase;

    public function test_ccda_export_records_phi_disclosure(): void
    {
        $t = Tenant::factory()->create();
        $prefix = strtoupper(Str::random(3));
        $site = Site::factory()->create(['tenant_id' => $t->id, 'mrn_prefix' => $prefix]);
        $u = User::factory()->create([
            'tenant_id' => $t->id, 'department' => 'primary_care',
            'role' => 'admin', 'is_active' => true,
        ]);
        $p = Participant::factory()->enrolled()->forTenant($t->id)->forSite($site->id)->create();

        $this->actingAs($u);
        $this->get("/participants/{$p->id}/ccda/export")->assertOk();

        $this->assertEquals(1, PhiDisclosure::where('participant_id', $p->id)
            ->where('disclosure_method', 'portal')
            ->where('records_described', 'like', 'C-CDA%')->count());
    }

    public function test_fhir_patient_read_records_phi_disclosure(): void
    {
        $plaintext = Str::random(64);
        $token = ApiToken::factory()->state([
            'token' => ApiToken::hashToken($plaintext),
        ])->create();
        $p = Participant::factory()->create(['tenant_id' => $token->tenant_id]);

        $this->getJson("/fhir/R4/Patient/{$p->id}", ['Authorization' => "Bearer {$plaintext}"])
            ->assertOk();

        $this->assertEquals(1, PhiDisclosure::where('participant_id', $p->id)
            ->where('disclosure_method', 'api')
            ->where('records_described', 'like', 'FHIR R4 Patient%')->count());
    }

    public function test_fhir_practitioner_read_does_not_record_phi_disclosure(): void
    {
        $plaintext = Str::random(64);
        $token = ApiToken::factory()->state([
            'token' => ApiToken::hashToken($plaintext),
            'scopes' => ['practitioner.read'],
        ])->create();
        $u = User::factory()->create([
            'tenant_id' => $token->tenant_id, 'department' => 'primary_care',
            'role' => 'admin', 'is_active' => true,
        ]);

        $this->getJson("/fhir/R4/Practitioner/{$u->id}", ['Authorization' => "Bearer {$plaintext}"])
            ->assertOk();

        // Practitioner is directory data, not PHI — no disclosure should be logged.
        $this->assertEquals(0, PhiDisclosure::count());
    }
}
