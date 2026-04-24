<?php

// ─── ConsentEsignatureTest ───────────────────────────────────────────────────
// Phase B8a — ESIGN/UETA signature capture on consent records.
// ─────────────────────────────────────────────────────────────────────────────

namespace Tests\Feature;

use App\Models\ConsentRecord;
use App\Models\Participant;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ConsentEsignatureTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private Site $site;
    private User $qa;
    private Participant $participant;
    private ConsentRecord $consent;

    private const DATA_URL = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNgYAAAAAMAASsJTYQAAAAASUVORK5CYII=';

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::factory()->create();
        $this->site = Site::factory()->create(['tenant_id' => $this->tenant->id, 'mrn_prefix' => 'ES']);
        $this->qa = User::factory()->create([
            'tenant_id' => $this->tenant->id, 'department' => 'qa_compliance',
            'role' => 'admin', 'is_active' => true,
        ]);
        $this->participant = Participant::factory()->enrolled()
            ->forTenant($this->tenant->id)->forSite($this->site->id)->create();
        $this->consent = ConsentRecord::create([
            'tenant_id' => $this->tenant->id, 'participant_id' => $this->participant->id,
            'consent_type' => 'treatment_consent', 'document_title' => 'Treatment',
            'status' => 'pending', 'created_by_user_id' => $this->qa->id,
        ]);
    }

    public function test_participant_can_e_sign_consent(): void
    {
        $this->actingAs($this->qa);
        $r = $this->postJson("/participants/{$this->participant->id}/consents/{$this->consent->id}/sign", [
            'signature_data_url' => self::DATA_URL,
        ]);
        $r->assertOk();

        $this->consent->refresh();
        $this->assertTrue($this->consent->isSigned());
        $this->assertTrue((bool) $this->consent->signed_by_participant);
        $this->assertEquals('acknowledged', $this->consent->status);
        $this->assertNotNull($this->consent->signed_at);
        $this->assertEquals(ConsentRecord::ESIGN_DISCLAIMER_VERSION, $this->consent->esign_disclaimer_version);
    }

    public function test_proxy_signature_records_both_fields(): void
    {
        $this->actingAs($this->qa);
        $this->postJson("/participants/{$this->participant->id}/consents/{$this->consent->id}/sign", [
            'signature_data_url'  => self::DATA_URL,
            'proxy_signer_name'   => 'Jane Doe',
            'proxy_relationship'  => 'Daughter (POA)',
            'representative_type' => 'poa',
        ])->assertOk();

        $this->consent->refresh();
        $this->assertFalse((bool) $this->consent->signed_by_participant);
        $this->assertEquals('Jane Doe', $this->consent->proxy_signer_name);
        $this->assertEquals('Daughter (POA)', $this->consent->proxy_relationship);
        $this->assertEquals('poa', $this->consent->representative_type);
    }

    public function test_proxy_partial_info_is_rejected(): void
    {
        $this->actingAs($this->qa);
        $r = $this->postJson("/participants/{$this->participant->id}/consents/{$this->consent->id}/sign", [
            'signature_data_url' => self::DATA_URL,
            'proxy_signer_name'  => 'Jane Doe',
            // proxy_relationship intentionally missing
        ]);
        $r->assertStatus(422);
        $this->assertEquals('proxy_info_incomplete', $r->json('error'));
    }

    public function test_double_sign_returns_409(): void
    {
        $this->actingAs($this->qa);
        $this->postJson("/participants/{$this->participant->id}/consents/{$this->consent->id}/sign", [
            'signature_data_url' => self::DATA_URL,
        ])->assertOk();

        $this->postJson("/participants/{$this->participant->id}/consents/{$this->consent->id}/sign", [
            'signature_data_url' => self::DATA_URL,
        ])->assertStatus(409);
    }

    public function test_invalid_signature_payload_rejected(): void
    {
        $this->actingAs($this->qa);
        $this->postJson("/participants/{$this->participant->id}/consents/{$this->consent->id}/sign", [
            'signature_data_url' => 'not-a-data-url',
        ])->assertStatus(422);
    }

    public function test_signed_pdf_renders_when_signed(): void
    {
        $this->actingAs($this->qa);
        $this->postJson("/participants/{$this->participant->id}/consents/{$this->consent->id}/sign", [
            'signature_data_url' => self::DATA_URL,
        ])->assertOk();

        $r = $this->get("/participants/{$this->participant->id}/consents/{$this->consent->id}/signed.pdf");
        $r->assertOk();
        $r->assertHeader('content-type', 'application/pdf');
    }

    public function test_signed_pdf_404_when_unsigned(): void
    {
        $this->actingAs($this->qa);
        $r = $this->get("/participants/{$this->participant->id}/consents/{$this->consent->id}/signed.pdf");
        $r->assertStatus(404);
    }

    public function test_cross_tenant_sign_blocked(): void
    {
        $other = Tenant::factory()->create();
        $otherSite = Site::factory()->create(['tenant_id' => $other->id, 'mrn_prefix' => 'XT']);
        $otherP = Participant::factory()->enrolled()->forTenant($other->id)->forSite($otherSite->id)->create();
        $otherUser = User::factory()->create([
            'tenant_id' => $other->id, 'department' => 'qa_compliance',
            'role' => 'admin', 'is_active' => true,
        ]);
        $otherConsent = ConsentRecord::create([
            'tenant_id' => $other->id, 'participant_id' => $otherP->id,
            'consent_type' => 'treatment_consent', 'document_title' => 'x',
            'status' => 'pending', 'created_by_user_id' => $otherUser->id,
        ]);

        $this->actingAs($this->qa);
        $r = $this->postJson("/participants/{$otherP->id}/consents/{$otherConsent->id}/sign", [
            'signature_data_url' => self::DATA_URL,
        ]);
        $r->assertStatus(403);
    }

    public function test_signature_is_encrypted_at_rest(): void
    {
        $this->actingAs($this->qa);
        $this->postJson("/participants/{$this->participant->id}/consents/{$this->consent->id}/sign", [
            'signature_data_url' => self::DATA_URL,
        ])->assertOk();

        // Raw DB row: the blob should NOT be the plaintext data URL (it's encrypted).
        $raw = \Illuminate\Support\Facades\DB::table('emr_consent_records')
            ->where('id', $this->consent->id)->value('signature_image_blob');
        $this->assertNotNull($raw);
        $this->assertStringNotContainsString('data:image/png', $raw);
        // But the model accessor should decrypt it back.
        $this->assertStringStartsWith('data:image/png', $this->consent->fresh()->signature_image_blob);
    }
}
