<?php

// ─── Phase12ClearinghouseTest ────────────────────────────────────────────────
// Phase 12 (MVP roadmap). Exercises the vendor-agnostic clearinghouse
// scaffolding. The real adapters (Availity / Change Healthcare / Office Ally)
// intentionally throw until a trading-partner agreement is signed, so tests
// focus on the NullClearinghouseGateway (default), the factory's adapter
// resolution, the config + transmission controllers, and the SLA alert job.
// ─────────────────────────────────────────────────────────────────────────────

namespace Tests\Feature;

use App\Jobs\DenialAppealDeadlineAlertJob;
use App\Models\Alert;
use App\Models\ClearinghouseConfig;
use App\Models\ClearinghouseTransmission;
use App\Models\DenialRecord;
use App\Models\EdiBatch;
use App\Models\Participant;
use App\Models\RemittanceBatch;
use App\Models\RemittanceClaim;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Clearinghouse\AvailityClearinghouseGateway;
use App\Services\Clearinghouse\ChangeHealthcareClearinghouseGateway;
use App\Services\Clearinghouse\ClearinghouseGatewayFactory;
use App\Services\Clearinghouse\NullClearinghouseGateway;
use App\Services\Clearinghouse\OfficeAllyClearinghouseGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class Phase12ClearinghouseTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private Site $site;
    private User $financeUser;
    private User $itAdmin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::factory()->create();
        $this->site   = Site::factory()->create(['tenant_id' => $this->tenant->id, 'mrn_prefix' => 'CH']);
        $this->financeUser = User::factory()->create([
            'tenant_id' => $this->tenant->id, 'department' => 'finance',
            'role' => 'admin', 'is_active' => true,
        ]);
        $this->itAdmin = User::factory()->create([
            'tenant_id' => $this->tenant->id, 'department' => 'it_admin',
            'role' => 'admin', 'is_active' => true,
        ]);
    }

    // ── Factory + adapter resolution ────────────────────────────────────────

    public function test_factory_returns_null_gateway_when_no_config_exists(): void
    {
        [$gateway, $cfg] = app(ClearinghouseGatewayFactory::class)->forTenant($this->tenant->id);
        $this->assertInstanceOf(NullClearinghouseGateway::class, $gateway);
        $this->assertEquals('null_gateway', $cfg->adapter);
        $this->assertNull($cfg->id); // synthetic, unpersisted
    }

    public function test_factory_resolves_active_vendor_config(): void
    {
        ClearinghouseConfig::create([
            'tenant_id' => $this->tenant->id, 'adapter' => 'availity',
            'display_name' => 'Availity prod', 'environment' => 'production',
            'is_active' => true,
        ]);

        [$gateway, $cfg] = app(ClearinghouseGatewayFactory::class)->forTenant($this->tenant->id);
        $this->assertInstanceOf(AvailityClearinghouseGateway::class, $gateway);
        $this->assertEquals('availity', $cfg->adapter);
    }

    public function test_factory_resolve_by_name(): void
    {
        $factory = app(ClearinghouseGatewayFactory::class);
        $this->assertInstanceOf(NullClearinghouseGateway::class, $factory->resolve('null_gateway'));
        $this->assertInstanceOf(AvailityClearinghouseGateway::class, $factory->resolve('availity'));
        $this->assertInstanceOf(ChangeHealthcareClearinghouseGateway::class, $factory->resolve('change_healthcare'));
        $this->assertInstanceOf(OfficeAllyClearinghouseGateway::class, $factory->resolve('office_ally'));
    }

    // ── Null gateway behavior ───────────────────────────────────────────────

    public function test_null_gateway_stages_batch_and_writes_transmission_row(): void
    {
        $batch = EdiBatch::create([
            'tenant_id' => $this->tenant->id, 'batch_type' => '837P',
            'file_name' => 'test.x12', 'record_count' => 1,
            'total_charge_amount' => 100.00, 'status' => 'draft',
            'submission_method' => 'clearinghouse', 'created_by_user_id' => $this->financeUser->id,
        ]);

        [$gateway, $cfg] = app(ClearinghouseGatewayFactory::class)->forTenant($this->tenant->id);
        $result = $gateway->transmitClaimBatch($batch, $cfg);

        $this->assertEquals('staged_manual', $result->status);
        $this->assertTrue($result->succeeded());
        $this->assertStringContainsString('No vendor configured', $result->message);
        $this->assertDatabaseHas('emr_clearinghouse_transmissions', [
            'edi_batch_id' => $batch->id,
            'adapter'      => 'null_gateway',
            'direction'    => 'outbound',
            'status'       => 'staged_manual',
        ]);
    }

    public function test_null_gateway_returns_zero_for_fetches_and_healthy(): void
    {
        $gateway = app(NullClearinghouseGateway::class);
        $cfg = new ClearinghouseConfig(['tenant_id' => $this->tenant->id, 'adapter' => 'null_gateway']);
        $this->assertEquals(0, $gateway->fetchAcknowledgments($cfg));
        $this->assertEquals(0, $gateway->fetchRemittance($cfg));
        $this->assertTrue($gateway->healthCheck($cfg));
    }

    // ── Vendor stub behavior ────────────────────────────────────────────────

    public function test_vendor_stubs_throw_not_wired_runtime_exception(): void
    {
        $cfg = ClearinghouseConfig::create([
            'tenant_id' => $this->tenant->id, 'adapter' => 'availity',
            'display_name' => 'Availity', 'environment' => 'sandbox', 'is_active' => true,
        ]);
        $batch = EdiBatch::create([
            'tenant_id' => $this->tenant->id, 'batch_type' => '837P',
            'file_name' => 't.x12', 'record_count' => 1, 'total_charge_amount' => 1.00,
            'status' => 'draft', 'submission_method' => 'clearinghouse',
            'created_by_user_id' => $this->financeUser->id,
        ]);

        $gateway = app(AvailityClearinghouseGateway::class);
        $this->expectException(\RuntimeException::class);
        $gateway->transmitClaimBatch($batch, $cfg);
    }

    public function test_vendor_stubs_health_check_returns_false(): void
    {
        $cfg = new ClearinghouseConfig(['adapter' => 'availity']);
        $this->assertFalse(app(AvailityClearinghouseGateway::class)->healthCheck($cfg));
        $this->assertFalse(app(ChangeHealthcareClearinghouseGateway::class)->healthCheck($cfg));
        $this->assertFalse(app(OfficeAllyClearinghouseGateway::class)->healthCheck($cfg));
    }

    // ── Controller: transmit endpoint honest-labels null gateway ────────────

    public function test_transmit_endpoint_stages_via_null_gateway_with_honest_label(): void
    {
        $batch = EdiBatch::create([
            'tenant_id' => $this->tenant->id, 'batch_type' => '837P',
            'file_name' => 't.x12', 'record_count' => 1, 'total_charge_amount' => 100,
            'status' => 'draft', 'submission_method' => 'clearinghouse',
            'created_by_user_id' => $this->financeUser->id,
        ]);

        $this->actingAs($this->financeUser);
        $resp = $this->postJson("/clearinghouse/batches/{$batch->id}/transmit");
        $resp->assertOk();
        $this->assertEquals('staged_manual', $resp->json('status'));
        $this->assertEquals('null_gateway', $resp->json('adapter'));
        $this->assertStringContainsString('staged for manual upload', $resp->json('honest_label'));
        $this->assertDatabaseHas('shared_audit_logs', ['action' => 'clearinghouse.transmitted']);
    }

    public function test_transmit_endpoint_returns_503_when_vendor_adapter_throws(): void
    {
        ClearinghouseConfig::create([
            'tenant_id' => $this->tenant->id, 'adapter' => 'change_healthcare',
            'display_name' => 'CH', 'environment' => 'sandbox', 'is_active' => true,
        ]);
        $batch = EdiBatch::create([
            'tenant_id' => $this->tenant->id, 'batch_type' => '837P',
            'file_name' => 't.x12', 'record_count' => 1, 'total_charge_amount' => 1,
            'status' => 'draft', 'submission_method' => 'clearinghouse',
            'created_by_user_id' => $this->financeUser->id,
        ]);

        $this->actingAs($this->financeUser);
        $resp = $this->postJson("/clearinghouse/batches/{$batch->id}/transmit");
        $resp->assertStatus(503);
        $this->assertDatabaseHas('emr_clearinghouse_transmissions', [
            'edi_batch_id' => $batch->id,
            'status'       => 'error',
        ]);
    }

    public function test_transmit_endpoint_blocks_non_finance_department(): void
    {
        $batch = EdiBatch::create([
            'tenant_id' => $this->tenant->id, 'batch_type' => '837P',
            'file_name' => 't.x12', 'record_count' => 1, 'total_charge_amount' => 1,
            'status' => 'draft', 'submission_method' => 'clearinghouse',
            'created_by_user_id' => $this->financeUser->id,
        ]);
        $activities = User::factory()->create([
            'tenant_id' => $this->tenant->id, 'department' => 'activities',
            'role' => 'standard', 'is_active' => true,
        ]);
        $this->actingAs($activities);
        $this->postJson("/clearinghouse/batches/{$batch->id}/transmit")->assertForbidden();
    }

    // ── Admin config CRUD ───────────────────────────────────────────────────

    public function test_it_admin_can_create_clearinghouse_config(): void
    {
        $this->actingAs($this->itAdmin);
        $resp = $this->postJson('/it-admin/clearinghouse-config', [
            'adapter' => 'availity',
            'display_name' => 'Availity pilot',
            'environment' => 'sandbox',
            'submitter_id' => 'SUB-123',
            'is_active' => true,
        ]);
        $resp->assertStatus(201);
        $this->assertDatabaseHas('emr_clearinghouse_configs', [
            'adapter' => 'availity', 'is_active' => true,
        ]);
    }

    public function test_activating_new_config_deactivates_prior_active(): void
    {
        $this->actingAs($this->itAdmin);
        $first = ClearinghouseConfig::create([
            'tenant_id' => $this->tenant->id, 'adapter' => 'null_gateway',
            'display_name' => 'Null', 'environment' => 'sandbox', 'is_active' => true,
        ]);
        $this->postJson('/it-admin/clearinghouse-config', [
            'adapter' => 'office_ally', 'display_name' => 'OA',
            'environment' => 'production', 'is_active' => true,
        ])->assertStatus(201);

        $this->assertFalse($first->fresh()->is_active);
    }

    public function test_non_it_admin_cannot_access_clearinghouse_config(): void
    {
        $this->actingAs($this->financeUser);
        $this->postJson('/it-admin/clearinghouse-config', [
            'adapter' => 'availity', 'display_name' => 'x', 'environment' => 'sandbox',
        ])->assertForbidden();
    }

    // ── SLA alert job ───────────────────────────────────────────────────────

    public function test_denial_appeal_deadline_job_creates_warning_alert(): void
    {
        $participant = Participant::factory()->enrolled()
            ->forTenant($this->tenant->id)->forSite($this->site->id)->create();
        $remBatch = RemittanceBatch::create([
            'tenant_id' => $this->tenant->id, 'payer_name' => 'Test Payer',
            'file_name' => 'era-test.835', 'edi_835_content' => 'ISA*...*IEA*', 'created_by_user_id' => $this->financeUser->id,
            'status' => 'processed', 'payment_amount' => 0, 'payment_method' => 'check',
            'payment_date' => now()->toDateString(),
        ]);
        $remClaim = RemittanceClaim::create([
            'tenant_id' => $this->tenant->id,
            'remittance_batch_id' => $remBatch->id,
            'patient_control_number' => 'PCN-1', 'claim_status' => 'denied',
            'submitted_amount' => 100, 'allowed_amount' => 0, 'paid_amount' => 0,
            'remittance_date' => now()->toDateString(),
        ]);
        $denial = DenialRecord::create([
            'tenant_id' => $this->tenant->id,
            'remittance_claim_id' => $remClaim->id,
            'denied_amount' => 100.00,
            'denial_category' => 'authorization',
            'denial_date' => now()->subDays(110)->toDateString(),
            'appeal_deadline' => now()->addDays(10)->toDateString(),
            'status' => 'open',
        ]);

        (new DenialAppealDeadlineAlertJob())->handle(app(\App\Services\AlertService::class));

        $this->assertDatabaseHas('emr_alerts', [
            'tenant_id'  => $this->tenant->id,
            'alert_type' => 'denial_appeal_deadline',
        ]);
    }

    public function test_denial_appeal_deadline_job_dedupes_within_24h(): void
    {
        $participant = Participant::factory()->enrolled()
            ->forTenant($this->tenant->id)->forSite($this->site->id)->create();
        $remBatch = RemittanceBatch::create([
            'tenant_id' => $this->tenant->id, 'payer_name' => 'P',
            'file_name' => 'era-dedupe.835', 'edi_835_content' => 'ISA*...*IEA*', 'created_by_user_id' => $this->financeUser->id,
            'status' => 'processed', 'payment_amount' => 0, 'payment_method' => 'check',
            'payment_date' => now()->toDateString(),
        ]);
        $remClaim = RemittanceClaim::create([
            'tenant_id' => $this->tenant->id,
            'remittance_batch_id' => $remBatch->id,
            'patient_control_number' => 'PCN-9', 'claim_status' => 'denied',
            'submitted_amount' => 100, 'allowed_amount' => 0, 'paid_amount' => 0,
            'remittance_date' => now()->toDateString(),
        ]);
        $denial = DenialRecord::create([
            'tenant_id' => $this->tenant->id, 'remittance_claim_id' => $remClaim->id,
            'denied_amount' => 100, 'denial_category' => 'authorization',
            'denial_date' => now()->subDays(115), 'appeal_deadline' => now()->addDays(5),
            'status' => 'open',
        ]);

        $job = new DenialAppealDeadlineAlertJob();
        $svc = app(\App\Services\AlertService::class);
        $job->handle($svc);
        $job->handle($svc);

        $this->assertEquals(1, Alert::where('alert_type', 'denial_appeal_deadline')
            ->where('tenant_id', $this->tenant->id)->count());
    }
}
