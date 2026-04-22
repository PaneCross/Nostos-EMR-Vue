<?php

// ─── CmsReconciliationSeeder ──────────────────────────────────────────────────
// Generates a synthetic MMR + TRR file per tenant for last month so the
// reconciliation dashboard has data to show. Runs both parsers to populate
// MmrRecord / TrrRecord + discrepancy flags.
//
// Intentionally injects realistic discrepancies:
//   - 1 unmatched MBI (CMS has a member we don't)
//   - 1 status mismatch (CMS-disenrolled, locally-enrolled)
//   - 1 capitation variance (amount off by >$1)
//   - 1 retroactive adjustment (non-zero adjustment_amount)
//   - 1 TRR rejection
//
// Phase 6 (MVP roadmap).
// ─────────────────────────────────────────────────────────────────────────────

namespace Database\Seeders;

use App\Models\CapitationRecord;
use App\Models\MmrFile;
use App\Models\Participant;
use App\Models\TrrFile;
use App\Models\User;
use App\Services\MmrParserService;
use App\Services\TrrParserService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

class CmsReconciliationSeeder extends Seeder
{
    public function run(): void
    {
        $enrolledByTenant = Participant::where('enrollment_status', 'enrolled')
            ->whereNotNull('medicare_id')
            ->with('tenant:id')
            ->get()
            ->groupBy('tenant_id');

        if ($enrolledByTenant->isEmpty()) {
            $this->command?->warn('No enrolled participants with Medicare IDs — skipping CMS reconciliation seed.');
            return;
        }

        $lastMonth = Carbon::now()->subMonth();
        $year  = $lastMonth->year;
        $month = $lastMonth->month;
        $periodYm = sprintf('%04d-%02d', $year, $month);

        foreach ($enrolledByTenant as $tenantId => $participants) {
            $finance = User::where('tenant_id', $tenantId)
                ->whereIn('department', ['finance', 'it_admin', 'super_admin'])
                ->value('id')
                ?? User::where('tenant_id', $tenantId)->value('id');
            if (! $finance) continue;

            // Seed expected capitation records for this period if missing (so
            // the variance check has a baseline to compare against).
            foreach ($participants as $p) {
                CapitationRecord::updateOrCreate([
                    'tenant_id'      => $tenantId,
                    'participant_id' => $p->id,
                    'month_year'     => $periodYm,
                ], [
                    'total_capitation' => 4200.00, // round baseline for demo
                ]);
            }

            // ── Build MMR file contents ──────────────────────────────────────
            $contractId = 'H9999';
            $mmrLines = ["HEADER|{$contractId}|" . sprintf('%04d%02d', $year, $month)];
            $totalCap = 0;

            $participants = $participants->values();
            foreach ($participants as $i => $p) {
                // Inject discrepancies on specific indices (wraps around).
                $mbi      = $p->medicare_id;
                $name     = $p->last_name . ', ' . $p->first_name;
                $status   = 'active';
                $from     = $lastMonth->copy()->startOfMonth()->toDateString();
                $through  = $lastMonth->copy()->endOfMonth()->toDateString();
                $cap      = 4200.00;
                $adj      = 0.00;

                if ($i === 0 && $participants->count() > 1) {
                    // Retroactive adjustment
                    $adj = 125.00;
                }
                if ($i === 1 && $participants->count() > 1) {
                    // Capitation variance
                    $cap = 4500.00;
                }

                $mmrLines[] = implode('|', [$mbi, $name, $status, $from, $through,
                    number_format($cap, 2, '.', ''), number_format($adj, 2, '.', '')]);
                $totalCap += $cap;
            }

            // Synthetic unmatched MBI (CMS enrolled, not local)
            $mmrLines[] = implode('|', [
                '9AB9CD8EF7GH', 'Doe, John', 'active',
                $lastMonth->copy()->startOfMonth()->toDateString(),
                $lastMonth->copy()->endOfMonth()->toDateString(),
                '4200.00', '0.00',
            ]);

            $mmrLines[] = "TRAILER|" . (count($mmrLines) - 1) . "|" . number_format($totalCap, 2, '.', '');

            $mmrFilename = "MMR_demo_{$tenantId}_{$year}_{$month}.txt";
            $storagePath = "mmr/{$tenantId}/" . $mmrFilename;
            Storage::disk('local')->put($storagePath, implode("\n", $mmrLines));

            $mmrFile = MmrFile::create([
                'tenant_id'           => $tenantId,
                'uploaded_by_user_id' => $finance,
                'period_year'         => $year,
                'period_month'        => $month,
                'contract_id'         => $contractId,
                'original_filename'   => $mmrFilename,
                'storage_path'        => $storagePath,
                'file_size_bytes'     => Storage::disk('local')->size($storagePath),
                'received_at'         => now(),
                'status'              => MmrFile::STATUS_RECEIVED,
            ]);

            app(MmrParserService::class)->parse($mmrFile, User::find($finance));

            // ── Build TRR file contents (1 rejection + a couple accepted) ────
            $trrLines = ["HEADER|{$contractId}"];
            // An accepted enrollment
            if ($participants->first()) {
                $p = $participants->first();
                $trrLines[] = implode('|', [
                    $p->medicare_id, '01', 'accepted',
                    'TRC001', 'Enrollment accepted',
                    $p->enrollment_date?->toDateString() ?: $lastMonth->copy()->startOfMonth()->toDateString(),
                    $lastMonth->toDateString(),
                ]);
            }
            // A rejection for a fake submission
            $trrLines[] = implode('|', [
                'BAD1234EF5GH', '01', 'rejected',
                'TRC014', 'MBI not found in CMS',
                $lastMonth->copy()->startOfMonth()->toDateString(),
                $lastMonth->toDateString(),
            ]);
            $trrLines[] = "TRAILER|" . (count($trrLines) - 1);

            $trrFilename = "TRR_demo_{$tenantId}_{$year}_{$month}.txt";
            $trrPath = "trr/{$tenantId}/" . $trrFilename;
            Storage::disk('local')->put($trrPath, implode("\n", $trrLines));

            $trrFile = TrrFile::create([
                'tenant_id'           => $tenantId,
                'uploaded_by_user_id' => $finance,
                'contract_id'         => $contractId,
                'original_filename'   => $trrFilename,
                'storage_path'        => $trrPath,
                'file_size_bytes'     => Storage::disk('local')->size($trrPath),
                'received_at'         => now(),
                'status'              => TrrFile::STATUS_RECEIVED,
            ]);

            app(TrrParserService::class)->parse($trrFile, User::find($finance));

            $this->command?->info("  Tenant {$tenantId}: MMR + TRR for {$periodYm} seeded (file #{$mmrFile->id}, #{$trrFile->id}).");
        }
    }
}
