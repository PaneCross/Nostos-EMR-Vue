<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\Participant;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Ocr\NullOcrGateway;
use App\Services\Ocr\OcrGateway;
use App\Services\Ocr\OcrService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OcrTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private Site $site;
    private User $user;
    private Participant $participant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::factory()->create();
        $this->site = Site::factory()->create(['tenant_id' => $this->tenant->id, 'mrn_prefix' => 'G6']);
        $this->user = User::factory()->create(['tenant_id' => $this->tenant->id, 'department' => 'it_admin', 'role' => 'admin', 'is_active' => true, 'site_id' => $this->site->id]);
        $this->participant = Participant::factory()->enrolled()->forTenant($this->tenant->id)->forSite($this->site->id)->create();
    }

    public function test_default_gateway_is_null(): void
    {
        $this->assertInstanceOf(NullOcrGateway::class, app(OcrGateway::class));
    }

    public function test_process_with_null_gateway_marks_processed_with_empty_text(): void
    {
        $doc = Document::create([
            'tenant_id' => $this->tenant->id, 'participant_id' => $this->participant->id,
            'file_name' => 'test.pdf', 'file_path' => 'fake/does-not-exist.pdf',
            'file_type' => 'pdf', 'file_size_bytes' => 100,
            'document_category' => 'clinical', 'uploaded_by_user_id' => $this->user->id,
            'uploaded_at' => now(),
        ]);
        $svc = new OcrService(new NullOcrGateway());
        $svc->process($doc, $this->user);
        $doc->refresh();
        $this->assertNotNull($doc->ocr_processed_at);
        $this->assertEquals('null', $doc->ocr_engine);
        $this->assertNull($doc->ocr_text);
    }

    public function test_field_extraction_from_text(): void
    {
        $text = "Admitted on: 2024-03-01\nDischarged on: 2024-03-08\nPrimary Diagnosis: Heart failure exacerbation\nDischarge Medications: Furosemide 40mg daily\nFollow-up: PCP within 7 days";
        $svc = new OcrService(new NullOcrGateway());
        $fields = $svc->extractFields($text);
        $this->assertArrayHasKey('admit_date', $fields);
        $this->assertArrayHasKey('discharge_date', $fields);
        $this->assertStringContainsString('Heart failure', $fields['primary_diagnosis']);
    }

    public function test_search_endpoint_ilike_matches_ocr_text(): void
    {
        Document::create([
            'tenant_id' => $this->tenant->id, 'participant_id' => $this->participant->id,
            'file_name' => 'a.pdf', 'file_path' => 'a.pdf', 'file_type' => 'pdf',
            'file_size_bytes' => 100, 'document_category' => 'clinical',
            'uploaded_by_user_id' => $this->user->id, 'uploaded_at' => now(),
            'ocr_text' => 'Primary Diagnosis: congestive heart failure exacerbation',
            'ocr_processed_at' => now(), 'ocr_engine' => 'tesseract',
        ]);
        $this->actingAs($this->user);
        $r = $this->getJson('/documents/search?q=congestive');
        $r->assertOk();
        $this->assertEquals(1, $r->json('count'));
    }

    public function test_search_rejects_short_query(): void
    {
        $this->actingAs($this->user);
        $r = $this->getJson('/documents/search?q=ab');
        $r->assertOk();
        $this->assertEquals(0, $r->json('count'));
    }
}
