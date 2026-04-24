<?php

// ─── Phase O1 — MOLST support + nursing dept dead-branch strip ─────────────
namespace Tests\Feature;

use App\Models\Participant;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class O1SchemaCleanupTest extends TestCase
{
    use RefreshDatabase;

    public function test_molst_accepted_as_advance_directive_type(): void
    {
        $t = Tenant::factory()->create();
        $site = Site::factory()->create(['tenant_id' => $t->id, 'mrn_prefix' => 'O1']);
        $p = Participant::factory()->enrolled()
            ->forTenant($t->id)->forSite($site->id)->create();
        $u = User::factory()->create([
            'tenant_id' => $t->id, 'department' => 'primary_care',
            'role' => 'standard', 'is_active' => true,
        ]);
        $this->actingAs($u);

        $png = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8//8/AwAI/AL+XJ4wCQAAAABJRU5ErkJggg==';

        $this->postJson("/participants/{$p->id}/advance-directive", [
            'ad_type' => 'molst',
            'choices' => ['code_status' => 'dnr'],
            'signature_data_url' => $png,
            'representative_type' => 'self',
        ])->assertStatus(201);

        $this->assertEquals('molst', $p->fresh()->advance_directive_type);
    }

    public function test_nursing_strings_removed_from_controller_allow_lists(): void
    {
        // Controllers should no longer reference the unreachable 'nursing'
        // department in their department allow-lists. Comments + domain labels
        // (e.g. CarePlanGoal's nursing goal domain) are still allowed.
        $controllers = [
            app_path('Http/Controllers/FormularyController.php'),
            app_path('Http/Controllers/ImmunizationSubmissionController.php'),
            app_path('Http/Controllers/CcdaController.php'),
            app_path('Http/Controllers/ClinicalDecisionSupportController.php'),
            app_path('Http/Controllers/MobileCompanionController.php'),
            app_path('Http/Controllers/AdvanceDirectivePdfController.php'),
        ];
        foreach ($controllers as $path) {
            $content = file_get_contents($path);
            $this->assertStringNotContainsString(
                "'nursing'",
                $content,
                "Controller {$path} still references 'nursing' department.",
            );
        }
    }
}
