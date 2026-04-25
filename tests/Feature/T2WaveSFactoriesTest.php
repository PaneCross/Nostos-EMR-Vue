<?php

// ─── Phase T2 — Smoke test for new Wave R-S factories ──────────────────────
namespace Tests\Feature;

use App\Models\CmsAuditUniverseAttempt;
use App\Models\ContractedProvider;
use App\Models\ContractedProviderContract;
use App\Models\ContractedProviderRate;
use App\Models\DmeIssuance;
use App\Models\DmeItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class T2WaveSFactoriesTest extends TestCase
{
    use RefreshDatabase;

    public function test_all_six_wave_rs_factories_create_clean(): void
    {
        $provider = ContractedProvider::factory()->create();
        $this->assertNotNull($provider->id);
        $this->assertContains($provider->provider_type, ContractedProvider::PROVIDER_TYPES);

        $contract = ContractedProviderContract::factory()->create();
        $this->assertNotNull($contract->id);
        $this->assertContains($contract->reimbursement_basis, ContractedProviderContract::REIMBURSEMENT_BASES);

        $rate = ContractedProviderRate::factory()->create();
        $this->assertNotNull($rate->id);
        $this->assertNotNull($rate->cpt_code);

        $item = DmeItem::factory()->create();
        $this->assertNotNull($item->id);
        $this->assertContains($item->status, DmeItem::STATUSES);

        $issuance = DmeIssuance::factory()->create();
        $this->assertNotNull($issuance->id);
        $this->assertNull($issuance->returned_at);

        $attempt = CmsAuditUniverseAttempt::factory()->create();
        $this->assertNotNull($attempt->id);
        $this->assertContains($attempt->universe, CmsAuditUniverseAttempt::UNIVERSES);
        $this->assertLessThanOrEqual(CmsAuditUniverseAttempt::MAX_ATTEMPTS, $attempt->attempt_number);
    }

    public function test_factory_states_work(): void
    {
        $this->assertEquals('issued', DmeItem::factory()->issued()->create()->status);
        $this->assertEquals('lost',   DmeItem::factory()->lost()->create()->status);
        $this->assertFalse(ContractedProvider::factory()->inactive()->create()->is_active);

        $returned = DmeIssuance::factory()->returned('damaged')->create();
        $this->assertNotNull($returned->returned_at);
        $this->assertEquals('damaged', $returned->return_condition);
    }
}
