<?php

// ─── Phase M4 — ML training + OCR cloud stubs ──────────────────────────────
namespace Tests\Feature;

use App\Models\PredictiveModelVersion;
use App\Models\Tenant;
use App\Services\Ocr\AwsTextractOcrGateway;
use App\Services\Ocr\GoogleDocumentAiOcrGateway;
use App\Services\PredictiveModelTrainingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class M4MlOcrTest extends TestCase
{
    use RefreshDatabase;

    public function test_training_produces_a_version_row(): void
    {
        $t = Tenant::factory()->create();
        $svc = new PredictiveModelTrainingService();
        $v = $svc->train($t->id, 'disenrollment');
        $this->assertInstanceOf(PredictiveModelVersion::class, $v);
        $this->assertEquals(1, $v->version_number);
        $this->assertEquals('disenrollment', $v->risk_type);
    }

    public function test_training_bumps_version_number(): void
    {
        $t = Tenant::factory()->create();
        $svc = new PredictiveModelTrainingService();
        $svc->train($t->id, 'disenrollment');
        $v2 = $svc->train($t->id, 'disenrollment');
        $this->assertEquals(2, $v2->version_number);
    }

    public function test_textract_gateway_stub_throws(): void
    {
        $gw = new AwsTextractOcrGateway();
        $this->expectException(\RuntimeException::class);
        $gw->extractText('/tmp/x.pdf');
    }

    public function test_document_ai_gateway_stub_throws(): void
    {
        $gw = new GoogleDocumentAiOcrGateway();
        $this->expectException(\RuntimeException::class);
        $gw->extractText('/tmp/x.pdf');
    }

    public function test_ocr_binding_uses_null_by_default(): void
    {
        config(['services.ocr.driver' => null]);
        $gw = app(\App\Services\Ocr\OcrGateway::class);
        $this->assertInstanceOf(\App\Services\Ocr\NullOcrGateway::class, $gw);
    }
}
