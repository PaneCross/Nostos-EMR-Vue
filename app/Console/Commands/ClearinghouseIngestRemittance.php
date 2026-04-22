<?php

// ─── ClearinghouseIngestRemittance ───────────────────────────────────────────
// Phase 12. Pulls any new 835 remittance files from each tenant's active
// clearinghouse gateway. Fresh files are handed to the existing
// Process835RemittanceJob for parsing via Remittance835ParserService.
//
// Under the default null gateway this command reports 0 files and exits
// cleanly — that's the honest-label behavior while no vendor contract is
// active. No error, just no work.
//
// Schedule (hourly) in routes/console.php / App\Console\Kernel::schedule().
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Console\Commands;

use App\Models\ClearinghouseConfig;
use App\Services\Clearinghouse\ClearinghouseGatewayFactory;
use Illuminate\Console\Command;

class ClearinghouseIngestRemittance extends Command
{
    protected $signature = 'clearinghouse:ingest-remittance {--tenant= : Limit to a single tenant id}';
    protected $description = 'Fetch new 835 ERA files from each tenant\'s clearinghouse and queue them for parsing.';

    public function handle(ClearinghouseGatewayFactory $factory): int
    {
        $query = ClearinghouseConfig::active();
        if ($tid = $this->option('tenant')) {
            $query->where('tenant_id', (int) $tid);
        }

        $total = 0;
        foreach ($query->get() as $cfg) {
            $gateway = $factory->resolve($cfg->adapter);
            try {
                $count = $gateway->fetchRemittance($cfg);
                $cfg->update(['last_successful_at' => now(), 'last_error' => null]);
                $this->info("Tenant {$cfg->tenant_id} [{$cfg->adapter}]: {$count} 835 file(s) fetched.");
                $total += $count;
            } catch (\Throwable $e) {
                $cfg->update(['last_failed_at' => now(), 'last_error' => substr($e->getMessage(), 0, 500)]);
                $this->warn("Tenant {$cfg->tenant_id} [{$cfg->adapter}]: {$e->getMessage()}");
            }
        }

        $this->line("Total 835 files ingested this run: {$total}");
        return self::SUCCESS;
    }
}
