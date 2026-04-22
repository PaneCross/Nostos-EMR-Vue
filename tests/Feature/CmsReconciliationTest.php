<?php

// ─── CmsReconciliationTest ────────────────────────────────────────────────────
// Phase 6 (MVP roadmap) — CMS MMR/TRR ingest + reconciliation.
// ─────────────────────────────────────────────────────────────────────────────

namespace Tests\Feature;

use App\Models\CapitationRecord;
use App\Models\MmrFile;
use App\Models\MmrRecord;
use App\Models\Participant;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\TrrFile;
use App\Models\TrrRecord;
use App\Models\User;
use App\Services\EnrollmentReconciliationService;
use App\Services\MmrParserService;
use App\Services\TrrParserService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CmsReconciliationTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private Site $site;
    private User $financeUser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::factory()->create();
        $this->site   = Site::factory()->create(['tenant_id' => $this->tenant->id, 'mrn_prefix' => 'REC']);
        $this->financeUser = User::factory()->create([
            'tenant_id'  => $this->tenant->id,
            'department' => 'finance',
            'role'       => 'admin',
            'is_active'  => true,
        ]);
    }

    private function ppt(string $mbi, array $overrides = []): Participant
    {
        return Participant::factory()->enrolled()
            ->forTenant($this->tenant->id)
            ->forSite($this->site->id)
            ->create(array_merge(['medicare_id' => $mbi], $overrides));
    }

    // ── Parser tests ─────────────────────────────────────────────────────────

    public function test_mmr_parser_reads_pipe_delimited_records(): void
    {
        $svc = app(MmrParserService::class);
        $contents = implode("\n", [
            'HEADER|H1234|202603',
            '1EG4TE5MK73|Smith, Alice|active|2026-03-01|2026-03-31|4200.00|0.00',
            '2FH5UF6NL84|Jones, Bob|disenrolled|2026-03-01|2026-03-15|2100.00|50.00',
            'TRAILER|2|6300.00',
        ]);
        [$records, $total, $contractId] = $svc->parseContents($contents);

        $this->assertCount(2, $records);
        $this->assertEquals('H1234', $contractId);
        $this->assertEquals(6300.00, $total);
        $this->assertEquals('1EG4TE5MK73', $records[0]['medicare_id']);
        $this->assertEquals(50.00, $records[1]['adjustment_amount']);
    }

    public function test_mmr_parser_skips_malformed_rows(): void
    {
        $contents = implode("\n", [
            'HEADER|H1|202603',
            'GOOD|Alice|active|2026-03-01|2026-03-31|4200|0',
            'malformed-row',
            '',
            'MOREFIELD|Bob|active|2026-03-01|2026-03-31|4200|0',
        ]);
        [$records] = app(MmrParserService::class)->parseContents($contents);
        $this->assertCount(2, $records);
    }

    public function test_trr_parser_reads_pipe_delimited_records(): void
    {
        $contents = implode("\n", [
            'HEADER|H1234',
            '1EG4TE5MK73|01|accepted|TRC001|Enrollment accepted|2026-03-01|2026-03-15',
            '2FH5UF6NL84|51|rejected|TRC014|MBI not found|2026-03-01|2026-03-15',
            'TRAILER|2',
        ]);
        [$records, $contractId, $counts] = app(TrrParserService::class)->parseContents($contents);

        $this->assertCount(2, $records);
        $this->assertEquals('H1234', $contractId);
        $this->assertEquals(1, $counts['accepted']);
        $this->assertEquals(1, $counts['rejected']);
        $this->assertEquals('rejected', $records[1]['transaction_result']);
    }

    // ── Reconciliation discrepancy detection ─────────────────────────────────

    private function buildMmrFileFromString(string $contents): MmrFile
    {
        $path = "mmr/{$this->tenant->id}/test_" . uniqid() . '.txt';
        Storage::disk('local')->put($path, $contents);
        return MmrFile::create([
            'tenant_id'           => $this->tenant->id,
            'uploaded_by_user_id' => $this->financeUser->id,
            'period_year'         => 2026,
            'period_month'        => 3,
            'original_filename'   => 'test.txt',
            'storage_path'        => $path,
            'file_size_bytes'     => strlen($contents),
            'received_at'         => now(),
            'status'              => MmrFile::STATUS_RECEIVED,
        ]);
    }

    public function test_detects_unmatched_mbi_discrepancy(): void
    {
        $this->ppt('MATCHING_MBI');

        $contents = "HEADER|H1|202603\nUNKNOWN_MBI|Ghost, Participant|active|2026-03-01|2026-03-31|4200|0\nMATCHING_MBI|Real, One|active|2026-03-01|2026-03-31|4200|0\n";
        $file = $this->buildMmrFileFromString($contents);

        app(MmrParserService::class)->parse($file, $this->financeUser);

        $unmatched = MmrRecord::where('mmr_file_id', $file->id)
            ->where('discrepancy_type', MmrRecord::DISC_UNMATCHED_MBI)->get();
        $this->assertCount(1, $unmatched);
        $this->assertEquals('UNKNOWN_MBI', $unmatched->first()->medicare_id);
    }

    public function test_detects_retroactive_adjustment(): void
    {
        $this->ppt('MBI_A');
        $contents = "HEADER|H1|202603\nMBI_A|A, A|active|2026-03-01|2026-03-31|4200|125.00\n";
        $file = $this->buildMmrFileFromString($contents);
        app(MmrParserService::class)->parse($file, $this->financeUser);

        $r = MmrRecord::where('mmr_file_id', $file->id)->first();
        $this->assertEquals(MmrRecord::DISC_RETROACTIVE_ADJUSTMENT, $r->discrepancy_type);
    }

    public function test_detects_cms_disenrolled_local_enrolled(): void
    {
        $this->ppt('MBI_B');
        $contents = "HEADER|H1|202603\nMBI_B|B, B|disenrolled|2026-03-01|2026-03-15|2100|0\n";
        $file = $this->buildMmrFileFromString($contents);
        app(MmrParserService::class)->parse($file, $this->financeUser);

        $r = MmrRecord::where('mmr_file_id', $file->id)->first();
        $this->assertEquals(MmrRecord::DISC_CMS_DISENROLLED_LOCAL_ENROLLED, $r->discrepancy_type);
    }

    public function test_detects_capitation_variance(): void
    {
        $p = $this->ppt('MBI_C');
        CapitationRecord::create([
            'tenant_id'        => $this->tenant->id,
            'participant_id'   => $p->id,
            'month_year'       => '2026-03',
            'total_capitation' => 4200.00,
        ]);
        // CMS paid 4500 — $300 variance should flag.
        $contents = "HEADER|H1|202603\nMBI_C|C, C|active|2026-03-01|2026-03-31|4500|0\n";
        $file = $this->buildMmrFileFromString($contents);
        app(MmrParserService::class)->parse($file, $this->financeUser);

        $r = MmrRecord::where('mmr_file_id', $file->id)->first();
        $this->assertEquals(MmrRecord::DISC_CAPITATION_VARIANCE, $r->discrepancy_type);
    }

    public function test_clean_record_has_no_discrepancy(): void
    {
        $p = $this->ppt('MBI_OK');
        CapitationRecord::create([
            'tenant_id'        => $this->tenant->id,
            'participant_id'   => $p->id,
            'month_year'       => '2026-03',
            'total_capitation' => 4200.00,
        ]);
        $contents = "HEADER|H1|202603\nMBI_OK|OK, OK|active|2026-03-01|2026-03-31|4200|0\n";
        $file = $this->buildMmrFileFromString($contents);
        app(MmrParserService::class)->parse($file, $this->financeUser);

        $r = MmrRecord::where('mmr_file_id', $file->id)->first();
        $this->assertNull($r->discrepancy_type);
    }

    public function test_reconciliation_summary_counts_locally_missing_from_mmr(): void
    {
        $this->ppt('MBI_X');  // locally enrolled but NOT in MMR
        $other = $this->ppt('MBI_Y');
        $contents = "HEADER|H1|202603\nMBI_Y|Y, Y|active|2026-03-01|2026-03-31|4200|0\n";
        $file = $this->buildMmrFileFromString($contents);
        app(MmrParserService::class)->parse($file, $this->financeUser);

        $summary = app(EnrollmentReconciliationService::class)->reconciliationSummary($file);
        $this->assertEquals(1, $summary['locally_enrolled_missing_from_mmr']);
    }

    // ── Controller + auth ───────────────────────────────────────────────────

    public function test_finance_user_can_view_reconciliation_page(): void
    {
        $this->actingAs($this->financeUser)
            ->get('/billing/reconciliation')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Billing/Reconciliation')
                ->has('mmrFiles')
                ->has('trrFiles')
                ->has('openDiscrepancies')
            );
    }

    public function test_random_dept_cannot_access_reconciliation(): void
    {
        $random = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'department' => 'activities',
            'role' => 'standard',
            'is_active' => true,
        ]);
        $this->actingAs($random)
            ->get('/billing/reconciliation')
            ->assertStatus(403);
    }

    public function test_upload_mmr_via_endpoint_parses_and_flags(): void
    {
        $this->ppt('UP_MBI');
        $contents = "HEADER|H1|202603\nUP_MBI|U, U|active|2026-03-01|2026-03-31|4200|50\n";
        $upload = UploadedFile::fake()->createWithContent('mmr.txt', $contents);

        $this->actingAs($this->financeUser)
            ->post('/billing/reconciliation/mmr', [
                'period_year'  => 2026,
                'period_month' => 3,
                'file'         => $upload,
            ])
            ->assertStatus(201);

        $this->assertDatabaseHas('emr_mmr_files', ['tenant_id' => $this->tenant->id, 'period_year' => 2026]);
        $this->assertDatabaseHas('emr_mmr_records', [
            'medicare_id' => 'UP_MBI',
            'discrepancy_type' => MmrRecord::DISC_RETROACTIVE_ADJUSTMENT,
        ]);
    }

    public function test_resolve_discrepancy_updates_status(): void
    {
        $this->ppt('RES_MBI');
        $contents = "HEADER|H1|202603\nRES_MBI|R, R|active|2026-03-01|2026-03-31|4200|75\n";
        $file = $this->buildMmrFileFromString($contents);
        app(MmrParserService::class)->parse($file, $this->financeUser);

        $record = MmrRecord::where('mmr_file_id', $file->id)->first();

        $this->actingAs($this->financeUser)
            ->postJson("/billing/reconciliation/discrepancies/{$record->id}/resolve", [
                'action' => 'resolved',
                'notes'  => 'Verified with CMS, no action needed.',
            ])
            ->assertOk();

        $this->assertEquals('resolved', $record->fresh()->resolution_status);
        $this->assertNotNull($record->fresh()->resolved_at);
    }

    public function test_tenant_isolation_on_reconciliation_view(): void
    {
        $otherTenant = Tenant::factory()->create();
        $otherFile = MmrFile::create([
            'tenant_id'           => $otherTenant->id,
            'uploaded_by_user_id' => $this->financeUser->id,
            'period_year'         => 2026,
            'period_month'        => 3,
            'original_filename'   => 'other.txt',
            'storage_path'        => 'mmr/other/other.txt',
            'file_size_bytes'     => 10,
            'received_at'         => now(),
            'status'              => 'parsed',
        ]);

        $this->actingAs($this->financeUser)
            ->getJson("/billing/reconciliation/mmr/{$otherFile->id}")
            ->assertStatus(403);
    }

    // ── Finance dashboard widget ─────────────────────────────────────────────

    public function test_finance_dashboard_cms_reconciliation_widget_returns_counts(): void
    {
        $this->ppt('W_A');
        $contents = "HEADER|H1|202603\nW_A|A|active|2026-03-01|2026-03-31|4200|200\n";
        $file = $this->buildMmrFileFromString($contents);
        app(MmrParserService::class)->parse($file, $this->financeUser);

        $this->actingAs($this->financeUser)
            ->getJson('/dashboards/finance/cms-reconciliation')
            ->assertOk()
            ->assertJsonStructure(['open_discrepancies_total', 'open_by_type', 'latest_mmr', 'trr_rejected_last_30d'])
            ->assertJsonPath('open_discrepancies_total', 1);
    }
}
