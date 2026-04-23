<?php

// ─── BcmaTest ────────────────────────────────────────────────────────────────
// Phase B4 — BCMA scan verification + wristband PDF + backfill + alert job.
// ─────────────────────────────────────────────────────────────────────────────

namespace Tests\Feature;

use App\Jobs\BcmaRepeatedOverrideAlertJob;
use App\Models\Alert;
use App\Models\EmarRecord;
use App\Models\Medication;
use App\Models\Participant;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BcmaTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private Site $site;
    private User $nurse;
    private Participant $participant;
    private Medication $medication;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::factory()->create();
        $this->site = Site::factory()->create(['tenant_id' => $this->tenant->id, 'mrn_prefix' => 'BC']);
        $this->nurse = User::factory()->create([
            'tenant_id'  => $this->tenant->id,
            'site_id'    => $this->site->id,
            'department' => 'primary_care',
            'role'       => 'admin',
            'is_active'  => true,
        ]);
        $this->participant = Participant::factory()->enrolled()
            ->forTenant($this->tenant->id)->forSite($this->site->id)->create();
        $this->medication = Medication::factory()
            ->forParticipant($this->participant->id)
            ->forTenant($this->tenant->id)
            ->create([
                'drug_name'           => 'Lisinopril',
                'status'              => 'active',
                'is_prn'              => false,
                'frequency'           => 'daily',
                'is_controlled'       => false,
                'controlled_schedule' => null,
            ]);
    }

    private function makeRecord(): EmarRecord
    {
        return EmarRecord::factory()
            ->forParticipant($this->participant->id)
            ->forTenant($this->tenant->id)
            ->create([
                'medication_id'  => $this->medication->id,
                'scheduled_time' => now()->setTime(8, 0),
                'status'         => 'scheduled',
            ]);
    }

    public function test_participant_barcode_auto_generated_on_create(): void
    {
        $this->assertNotNull($this->participant->fresh()->barcode_value);
        $this->assertStringStartsWith('PT-', $this->participant->fresh()->barcode_value);
    }

    public function test_medication_barcode_auto_generated_on_create(): void
    {
        $this->assertNotNull($this->medication->fresh()->barcode_value);
        $this->assertStringStartsWith('MD-', $this->medication->fresh()->barcode_value);
    }

    public function test_scan_verify_ok_path_stamps_both_timestamps(): void
    {
        $record = $this->makeRecord();
        $this->actingAs($this->nurse);
        $r = $this->postJson("/emar/{$record->id}/scan-verify", [
            'participant_barcode' => $this->participant->fresh()->barcode_value,
            'medication_barcode'  => $this->medication->fresh()->barcode_value,
        ]);
        $r->assertOk();
        $r->assertJsonPath('status', 'ok');
        $record->refresh();
        $this->assertNotNull($record->barcode_scanned_participant_at);
        $this->assertNotNull($record->barcode_scanned_med_at);
        $this->assertNull($record->barcode_mismatch_overridden_by_user_id);
    }

    public function test_mismatch_without_override_returns_422(): void
    {
        $record = $this->makeRecord();
        $this->actingAs($this->nurse);
        $r = $this->postJson("/emar/{$record->id}/scan-verify", [
            'participant_barcode' => 'PT-999-WRONG',
            'medication_barcode'  => 'MD-999-WRONG',
        ]);
        $r->assertStatus(422);
        $r->assertJsonPath('status', 'mismatch');
        $this->assertNull($record->fresh()->barcode_scanned_participant_at);
    }

    public function test_missing_scan_returns_422(): void
    {
        $record = $this->makeRecord();
        $this->actingAs($this->nurse);
        $r = $this->postJson("/emar/{$record->id}/scan-verify", [
            'participant_barcode' => $this->participant->fresh()->barcode_value,
            // medication_barcode missing
        ]);
        $r->assertStatus(422);
        $r->assertJsonPath('status', 'missing_scan');
    }

    public function test_mismatch_with_override_succeeds_and_emits_alert(): void
    {
        $record = $this->makeRecord();
        $this->actingAs($this->nurse);
        $r = $this->postJson("/emar/{$record->id}/scan-verify", [
            'participant_barcode' => 'PT-999-WRONG',
            'medication_barcode'  => 'MD-999-WRONG',
            'override_reason'     => 'Barcode printer jammed; verifying identity by secondary ID band + photo.',
        ]);
        $r->assertOk();
        $r->assertJsonPath('status', 'override');
        $record->refresh();
        $this->assertEquals($this->nurse->id, $record->barcode_mismatch_overridden_by_user_id);
        $this->assertNotNull($record->barcode_override_reason_text);

        $this->assertTrue(Alert::where('tenant_id', $this->tenant->id)
            ->where('alert_type', 'bcma_override')
            ->exists());
    }

    public function test_cross_tenant_scan_verify_is_blocked(): void
    {
        $other = Tenant::factory()->create();
        $otherSite = Site::factory()->create(['tenant_id' => $other->id, 'mrn_prefix' => 'XT']);
        $otherP = Participant::factory()->enrolled()
            ->forTenant($other->id)->forSite($otherSite->id)->create();
        $otherMed = Medication::factory()
            ->forParticipant($otherP->id)
            ->forTenant($other->id)
            ->create(['is_controlled' => false, 'controlled_schedule' => null, 'status' => 'active']);
        $otherRecord = EmarRecord::factory()
            ->forParticipant($otherP->id)
            ->forTenant($other->id)
            ->create(['medication_id' => $otherMed->id, 'scheduled_time' => now()]);

        $this->actingAs($this->nurse);
        $r = $this->postJson("/emar/{$otherRecord->id}/scan-verify", [
            'participant_barcode' => $otherP->barcode_value,
            'medication_barcode'  => $otherMed->barcode_value,
        ]);
        $r->assertStatus(403);
    }

    public function test_repeated_override_job_fires_at_3_overrides_in_7_days(): void
    {
        // Seed 3 overridden records for the same nurse.
        for ($i = 0; $i < 3; $i++) {
            EmarRecord::factory()
                ->forParticipant($this->participant->id)
                ->forTenant($this->tenant->id)
                ->create([
                    'medication_id'                           => $this->medication->id,
                    'scheduled_time'                          => now()->subDays($i)->setTime(8, 0),
                    'barcode_scanned_participant_at'          => now()->subDays($i),
                    'barcode_scanned_med_at'                  => now()->subDays($i),
                    'barcode_mismatch_overridden_by_user_id'  => $this->nurse->id,
                    'barcode_override_reason_text'            => 'test override',
                ]);
        }

        (new BcmaRepeatedOverrideAlertJob())->handle(app(\App\Services\AlertService::class));

        $this->assertTrue(Alert::where('alert_type', 'bcma_repeated_override')
            ->whereRaw("(metadata->>'user_id')::int = ?", [$this->nurse->id])
            ->exists());
    }

    public function test_wristband_pdf_renders_for_same_tenant(): void
    {
        $this->actingAs($this->nurse);
        $r = $this->get("/participants/{$this->participant->id}/wristband.pdf");
        $r->assertOk();
        $r->assertHeader('content-type', 'application/pdf');
    }

    public function test_backfill_command_fills_missing_barcodes(): void
    {
        // Create participant with barcode then null it to simulate a pre-B4 row.
        $p = Participant::factory()->enrolled()
            ->forTenant($this->tenant->id)->forSite($this->site->id)->create();
        $p->update(['barcode_value' => null]);

        $this->artisan('bcma:backfill-barcodes', ['--tenant' => $this->tenant->id])
            ->assertExitCode(0);

        $this->assertNotNull($p->fresh()->barcode_value);
    }
}
