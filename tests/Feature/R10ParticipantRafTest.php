<?php

// ─── Phase R10 — clinician-accessible per-participant RAF + V28 ────────────
namespace Tests\Feature;

use App\Models\HccMapping;
use App\Models\Participant;
use App\Models\Problem;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class R10ParticipantRafTest extends TestCase
{
    use RefreshDatabase;

    public function test_raf_snapshot_returns_v28_score_and_gaps(): void
    {
        $year = (int) now()->year;
        $t = Tenant::factory()->create();
        $site = Site::factory()->create(['tenant_id' => $t->id, 'mrn_prefix' => 'RA']);
        $u = User::factory()->create([
            'tenant_id' => $t->id, 'site_id' => $site->id,
            'department' => 'primary_care', 'role' => 'admin', 'is_active' => true,
        ]);
        $p = Participant::factory()->enrolled()->forTenant($t->id)->forSite($site->id)->create();

        // Map ICD E1140 to HCC 18 with RAF 0.318 in current year
        HccMapping::create([
            'icd10_code' => 'E1140', 'hcc_category' => '18',
            'hcc_label' => 'Diabetes with Chronic Complications', 'raf_value' => 0.3180,
            'effective_year' => $year,
        ]);
        Problem::create([
            'tenant_id' => $t->id, 'participant_id' => $p->id,
            'description' => 'Diabetes', 'icd10_code' => 'E1140',
            'icd10_description' => 'Type 2 diabetes mellitus with diabetic neuropathy, unspecified',
            'onset_date' => now()->subYear()->toDateString(), 'status' => 'active',
        ]);

        $r = $this->actingAs($u)->getJson("/participants/{$p->id}/raf-snapshot?year={$year}");
        $r->assertOk()
            ->assertJsonPath('model_label', 'CMS-HCC V28')
            ->assertJsonPath('current.raf_score', 0.318)
            ->assertJsonPath('current_year', $year)
            ->assertJsonStructure(['hcc_gaps', 'gap_count', 'delta', 'prior']);
    }

    public function test_raf_endpoint_blocks_unauthorized_dept(): void
    {
        $t = Tenant::factory()->create();
        $site = Site::factory()->create(['tenant_id' => $t->id, 'mrn_prefix' => 'RB']);
        $u = User::factory()->create([
            'tenant_id' => $t->id, 'site_id' => $site->id,
            'department' => 'activities', 'role' => 'standard', 'is_active' => true,
        ]);
        $p = Participant::factory()->enrolled()->forTenant($t->id)->forSite($site->id)->create();

        $this->actingAs($u)->getJson("/participants/{$p->id}/raf-snapshot")->assertStatus(403);
    }
}
