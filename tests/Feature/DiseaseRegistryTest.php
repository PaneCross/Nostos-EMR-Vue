<?php

namespace Tests\Feature;

use App\Models\Medication;
use App\Models\Participant;
use App\Models\Problem;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\User;
use App\Services\DiseaseRegistryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DiseaseRegistryTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private Site $site;
    private User $pcp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::factory()->create();
        $this->site = Site::factory()->create(['tenant_id' => $this->tenant->id, 'mrn_prefix' => 'G2']);
        $this->pcp = User::factory()->create(['tenant_id' => $this->tenant->id, 'department' => 'primary_care', 'role' => 'admin', 'is_active' => true, 'site_id' => $this->site->id]);
    }

    private function participantWith(string $icd10, string $desc): Participant
    {
        $p = Participant::factory()->enrolled()
            ->forTenant($this->tenant->id)->forSite($this->site->id)->create(['dob' => now()->subYears(65)]);
        Problem::create([
            'tenant_id' => $this->tenant->id, 'participant_id' => $p->id,
            'icd10_code' => $icd10, 'icd10_description' => $desc,
            'status' => 'active', 'onset_date' => now()->subYears(2),
        ]);
        return $p;
    }

    public function test_diabetes_cohort_pulls_e11_patients(): void
    {
        $p = $this->participantWith('E11.9', 'Type 2 diabetes');
        $other = $this->participantWith('I10', 'Essential hypertension');
        $c = (new DiseaseRegistryService())->cohort($this->tenant->id, 'diabetes');
        $ids = $c['rows']->pluck('id')->all();
        $this->assertContains($p->id, $ids);
        $this->assertNotContains($other->id, $ids);
    }

    public function test_chf_cohort_pulls_i50(): void
    {
        $p = $this->participantWith('I50.9', 'Heart failure');
        $c = (new DiseaseRegistryService())->cohort($this->tenant->id, 'chf');
        $this->assertGreaterThanOrEqual(1, $c['count']);
    }

    public function test_copd_cohort_pulls_j44(): void
    {
        $p = $this->participantWith('J44.9', 'COPD');
        Medication::factory()->forParticipant($p->id)->forTenant($this->tenant->id)
            ->create(['drug_name' => 'Albuterol HFA', 'status' => 'active', 'is_controlled' => false, 'controlled_schedule' => null]);
        $c = (new DiseaseRegistryService())->cohort($this->tenant->id, 'copd');
        $row = $c['rows']->firstWhere('id', $p->id);
        $this->assertNotEmpty($row['inhalers']);
    }

    public function test_unknown_registry_returns_422(): void
    {
        $this->actingAs($this->pcp);
        $this->getJson('/registries/bogus')->assertStatus(422);
    }

    public function test_csv_export_returns_csv(): void
    {
        $this->participantWith('E11.9', 'Type 2 diabetes');
        $this->actingAs($this->pcp);
        $r = $this->get('/registries/diabetes/export');
        $r->assertOk();
        $this->assertStringStartsWith('text/csv', $r->headers->get('content-type'));
    }
}
