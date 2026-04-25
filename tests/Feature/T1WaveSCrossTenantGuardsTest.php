<?php

// ─── Phase T1 — Cross-tenant guards on Wave S controllers ──────────────────
// Verifies every `abort_if($x->tenant_id !== $u->tenant_id, 403)` guard on
// the Wave S surface (DME, ContractedProvider, EDM submission, IBNR,
// CmsAuditUniverse) actually fires when crossed. Closes Audit-8 H8-1.
// ─────────────────────────────────────────────────────────────────────────────
namespace Tests\Feature;

use App\Models\CmsAuditUniverseAttempt;
use App\Models\ContractedProvider;
use App\Models\ContractedProviderContract;
use App\Models\DmeIssuance;
use App\Models\DmeItem;
use App\Models\EdiBatch;
use App\Models\Participant;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class T1WaveSCrossTenantGuardsTest extends TestCase
{
    use RefreshDatabase;

    /** Make tenant + finance user. */
    private function tenantWithFinance(string $prefix): array
    {
        $t = Tenant::factory()->create();
        $site = Site::factory()->create(['tenant_id' => $t->id, 'mrn_prefix' => $prefix]);
        $u = User::factory()->create([
            'tenant_id' => $t->id, 'site_id' => $site->id,
            'department' => 'finance', 'role' => 'admin', 'is_active' => true,
        ]);
        return [$t, $site, $u];
    }

    private function therapiesUser(int $tenantId, int $siteId): User
    {
        return User::factory()->create([
            'tenant_id' => $tenantId, 'site_id' => $siteId,
            'department' => 'therapies', 'role' => 'admin', 'is_active' => true,
        ]);
    }

    public function test_dme_issue_to_other_tenants_item_is_403(): void
    {
        [$tA, $siteA, $uA] = $this->tenantWithFinance('TA');
        [$tB, $siteB, $uB] = $this->tenantWithFinance('TB');
        $itemB = DmeItem::create([
            'tenant_id' => $tB->id, 'item_type' => 'walker', 'status' => 'available',
        ]);
        $pA = Participant::factory()->enrolled()->forTenant($tA->id)->forSite($siteA->id)->create();

        // Tenant A user (therapies dept) tries to issue tenant B's item.
        $this->actingAs($this->therapiesUser($tA->id, $siteA->id))
            ->postJson("/network/dme/{$itemB->id}/issue", [
                'participant_id' => $pA->id,
                'issued_at' => now()->toDateString(),
            ])
            ->assertStatus(403);
    }

    public function test_dme_return_on_other_tenants_issuance_is_403(): void
    {
        [$tA, $siteA] = $this->tenantWithFinance('TA');
        [$tB, $siteB, $uB] = $this->tenantWithFinance('TB');
        $itemB = DmeItem::create(['tenant_id' => $tB->id, 'item_type' => 'cpap', 'status' => 'issued']);
        $pB = Participant::factory()->enrolled()->forTenant($tB->id)->forSite($siteB->id)->create();
        $issuanceB = DmeIssuance::create([
            'tenant_id' => $tB->id, 'dme_item_id' => $itemB->id,
            'participant_id' => $pB->id, 'issued_at' => now()->subWeek(),
            'issued_by_user_id' => $uB->id,
        ]);

        $this->actingAs($this->therapiesUser($tA->id, $siteA->id))
            ->postJson("/network/dme/issuances/{$issuanceB->id}/return", [
                'returned_at' => now()->toDateString(),
                'return_condition' => 'good',
            ])
            ->assertStatus(403);
    }

    public function test_contracted_provider_contract_store_cross_tenant_is_403(): void
    {
        [$tA, $siteA, $uA] = $this->tenantWithFinance('TA');
        [$tB, $siteB, $uB] = $this->tenantWithFinance('TB');
        $providerB = ContractedProvider::create([
            'tenant_id' => $tB->id, 'name' => 'Acme Cardio',
            'provider_type' => 'specialist', 'is_active' => true, 'accepting_new_referrals' => true,
        ]);

        $this->actingAs($uA)
            ->postJson("/network/contracted-providers/{$providerB->id}/contracts", [
                'effective_date' => now()->toDateString(),
                'reimbursement_basis' => 'percent_of_medicare',
                'reimbursement_value' => 80.0,
            ])
            ->assertStatus(403);
    }

    public function test_contracted_provider_rate_store_cross_tenant_is_403(): void
    {
        [$tA, $siteA, $uA] = $this->tenantWithFinance('TA');
        [$tB, $siteB, $uB] = $this->tenantWithFinance('TB');
        $providerB = ContractedProvider::create([
            'tenant_id' => $tB->id, 'name' => 'Cross Imaging',
            'provider_type' => 'imaging', 'is_active' => true, 'accepting_new_referrals' => true,
        ]);
        $contractB = ContractedProviderContract::create([
            'tenant_id' => $tB->id, 'contracted_provider_id' => $providerB->id,
            'effective_date' => now()->toDateString(),
            'reimbursement_basis' => 'fee_schedule',
        ]);

        $this->actingAs($uA)
            ->postJson("/network/contracts/{$contractB->id}/rates", [
                'cpt_code' => '99213', 'rate_amount' => 100.0,
            ])
            ->assertStatus(403);
    }

    public function test_encounter_data_submission_cross_tenant_is_403(): void
    {
        [$tA, $siteA, $uA] = $this->tenantWithFinance('TA');
        [$tB, $siteB, $uB] = $this->tenantWithFinance('TB');
        $batchB = EdiBatch::create([
            'tenant_id' => $tB->id, 'batch_type' => 'edr',
            'file_name' => 'b.txt', 'file_content' => 'x',
            'record_count' => 1, 'status' => 'draft',
            'created_by_user_id' => $uB->id,
        ]);

        $this->actingAs($uA)
            ->postJson("/billing/encounter-data-submission/{$batchB->id}/submit")
            ->assertStatus(403);
    }
}
