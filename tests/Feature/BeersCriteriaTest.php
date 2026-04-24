<?php

namespace Tests\Feature;

use App\Jobs\PolypharmacyReviewQueueJob;
use App\Models\Medication;
use App\Models\Participant;
use App\Models\PolypharmacyReview;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\User;
use App\Services\BeersCriteriaService;
use Database\Seeders\BeersCriteriaSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BeersCriteriaTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private Site $site;
    private Participant $participant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::factory()->create();
        $this->site = Site::factory()->create(['tenant_id' => $this->tenant->id, 'mrn_prefix' => 'BR']);
        $this->participant = Participant::factory()->enrolled()
            ->forTenant($this->tenant->id)->forSite($this->site->id)->create();
        (new BeersCriteriaSeeder())->run();
    }

    private function addMed(string $name): Medication
    {
        return Medication::factory()
            ->forParticipant($this->participant->id)
            ->forTenant($this->tenant->id)
            ->create(['drug_name' => $name, 'status' => 'active',
                'is_controlled' => false, 'controlled_schedule' => null]);
    }

    public function test_seeder_populates_reference(): void
    {
        $this->assertGreaterThanOrEqual(25, \App\Models\BeersCriterion::count());
    }

    public function test_evaluate_flags_diphenhydramine(): void
    {
        $this->addMed('Diphenhydramine 25mg');
        $flags = (new BeersCriteriaService())->evaluate($this->participant);
        $this->assertCount(1, $flags);
        $this->assertStringContainsString('anticholinergic', $flags[0]['flags'][0]['risk_category']);
    }

    public function test_evaluate_returns_empty_for_safe_meds(): void
    {
        $this->addMed('Lisinopril 10mg');
        $flags = (new BeersCriteriaService())->evaluate($this->participant);
        $this->assertCount(0, $flags);
    }

    public function test_polypharmacy_flag_at_10_meds(): void
    {
        $svc = new BeersCriteriaService();
        $this->assertFalse($svc->isPolypharmacy($this->participant));
        for ($i = 0; $i < 10; $i++) $this->addMed("Drug-{$i}");
        $this->assertTrue($svc->isPolypharmacy($this->participant));
    }

    public function test_polypharmacy_job_creates_review_row(): void
    {
        for ($i = 0; $i < 11; $i++) $this->addMed("Drug-{$i}");
        (new PolypharmacyReviewQueueJob())->handle(app(BeersCriteriaService::class));
        $this->assertEquals(1, PolypharmacyReview::count());
        $row = PolypharmacyReview::first();
        $this->assertEquals(11, $row->active_med_count_at_queue);
        $this->assertNull($row->reviewed_at);
    }

    public function test_polypharmacy_job_skips_if_recently_queued(): void
    {
        for ($i = 0; $i < 11; $i++) $this->addMed("Drug-{$i}");
        PolypharmacyReview::create([
            'tenant_id' => $this->tenant->id, 'participant_id' => $this->participant->id,
            'active_med_count_at_queue' => 11, 'queued_at' => now()->subDays(30),
        ]);
        (new PolypharmacyReviewQueueJob())->handle(app(BeersCriteriaService::class));
        $this->assertEquals(1, PolypharmacyReview::count());
    }

    public function test_polypharmacy_job_reseeds_after_180_days(): void
    {
        for ($i = 0; $i < 11; $i++) $this->addMed("Drug-{$i}");
        PolypharmacyReview::create([
            'tenant_id' => $this->tenant->id, 'participant_id' => $this->participant->id,
            'active_med_count_at_queue' => 11,
            'queued_at' => now()->subDays(200),
            'reviewed_at' => now()->subDays(190),
        ]);
        (new PolypharmacyReviewQueueJob())->handle(app(BeersCriteriaService::class));
        $this->assertEquals(2, PolypharmacyReview::count());
    }
}
