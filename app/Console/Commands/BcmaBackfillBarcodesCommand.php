<?php

// ─── BcmaBackfillBarcodesCommand ─────────────────────────────────────────────
// Phase B4. Generates barcode_value for every participant and medication that
// doesn't have one. Idempotent : only touches rows where barcode_value IS NULL.
//
// Formats:
//   Participants: "PT-<tenant_id>-<mrn>"  (MRN already includes site prefix)
//   Medications:  "MD-<tenant_id>-<id>"
//
// Run manually:   php artisan bcma:backfill-barcodes
// Run for tenant: php artisan bcma:backfill-barcodes --tenant=5
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Console\Commands;

use App\Models\Medication;
use App\Models\Participant;
use Illuminate\Console\Command;

class BcmaBackfillBarcodesCommand extends Command
{
    protected $signature = 'bcma:backfill-barcodes {--tenant= : Limit to one tenant id}';
    protected $description = 'Phase B4 : backfill participant + medication barcode_value for BCMA.';

    public function handle(): int
    {
        $tenantFilter = $this->option('tenant');

        $participantQuery = Participant::query()->whereNull('barcode_value');
        $medQuery         = Medication::query()->whereNull('barcode_value');
        if ($tenantFilter) {
            $participantQuery->where('tenant_id', (int) $tenantFilter);
            $medQuery->where('tenant_id', (int) $tenantFilter);
        }

        $pCount = 0;
        $participantQuery->chunkById(500, function ($chunk) use (&$pCount) {
            foreach ($chunk as $p) {
                if (! $p->mrn) continue;
                $p->barcode_value = "PT-{$p->tenant_id}-{$p->mrn}";
                $p->save();
                $pCount++;
            }
        });

        $mCount = 0;
        $medQuery->chunkById(500, function ($chunk) use (&$mCount) {
            foreach ($chunk as $m) {
                $m->barcode_value = "MD-{$m->tenant_id}-{$m->id}";
                $m->save();
                $mCount++;
            }
        });

        $this->info("BCMA backfill complete. Participants: {$pCount}. Medications: {$mCount}.");
        return self::SUCCESS;
    }
}
