<?php

// ─── Phase O6 — trained-model path for predictive scoring ──────────────────
namespace Tests\Feature;

use App\Models\Participant;
use App\Models\PredictiveModelVersion;
use App\Models\Site;
use App\Models\Tenant;
use App\Services\PredictiveRiskService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class O6PredictiveScoringIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_heuristic_path_when_no_trained_version(): void
    {
        $t = Tenant::factory()->create();
        $site = Site::factory()->create(['tenant_id' => $t->id, 'mrn_prefix' => 'O6']);
        $p = Participant::factory()->enrolled()->forTenant($t->id)->forSite($site->id)->create();

        $score = app(PredictiveRiskService::class)->scoreType($p, 'disenrollment');
        $this->assertEquals('g8-v1-demo', $score->model_version);
        $this->assertNull($score->model_version_id);
    }

    public function test_trained_model_path_when_version_exists(): void
    {
        $t = Tenant::factory()->create();
        $site = Site::factory()->create(['tenant_id' => $t->id, 'mrn_prefix' => 'O6']);
        $p = Participant::factory()->enrolled()->forTenant($t->id)->forSite($site->id)->create();
        $v = PredictiveModelVersion::create([
            'tenant_id' => $t->id,
            'risk_type' => 'disenrollment',
            'version_number' => 3,
            'algorithm' => 'logistic_regression',
            'coefficients' => ['lace' => 2.0, 'recent_hosp' => 1.5, 'age' => 0.5],
            'training_accuracy' => 0.82,
            'training_sample_size' => 100,
            'trained_at' => now(),
        ]);

        $score = app(PredictiveRiskService::class)->scoreType($p, 'disenrollment');
        $this->assertEquals('trained-v3', $score->model_version);
        $this->assertEquals($v->id, $score->model_version_id);
        $this->assertIsArray($score->factors);
        $this->assertArrayHasKey('lace', $score->factors);
        $this->assertArrayHasKey('coefficient', $score->factors['lace']);
    }

    public function test_training_then_scoring_roundtrip_uses_trained_output(): void
    {
        $t = Tenant::factory()->create();
        $site = Site::factory()->create(['tenant_id' => $t->id, 'mrn_prefix' => 'O6']);
        $p = Participant::factory()->enrolled()->forTenant($t->id)->forSite($site->id)->create();

        // Seed one heuristic score, then "train", then re-score.
        $svcRisk = app(PredictiveRiskService::class);
        $first = $svcRisk->scoreType($p, 'disenrollment');
        $this->assertNull($first->model_version_id);

        app(\App\Services\PredictiveModelTrainingService::class)->train($t->id, 'disenrollment');

        $second = $svcRisk->scoreType($p, 'disenrollment');
        $this->assertNotNull($second->model_version_id);
        $this->assertStringStartsWith('trained-v', $second->model_version);
    }
}
