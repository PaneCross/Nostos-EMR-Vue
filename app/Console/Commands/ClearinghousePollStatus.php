<?php

// ─── ClearinghousePollStatus ─────────────────────────────────────────────────
// Phase 12. Refreshes status on in-flight 837P batches (status='submitted' or
// 'pending') by asking each tenant's active clearinghouse gateway for an
// update. Inbound 277CA / 999 acknowledgments are fetched and recorded.
//
// Null gateway: no-op (0 acks fetched).
// Schedule (every 15 minutes) via App\Console\Kernel::schedule().
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Console\Commands;

use App\Models\ClearinghouseConfig;
use App\Models\EdiBatch;
use App\Services\Clearinghouse\ClearinghouseGatewayFactory;
use Illuminate\Console\Command;

class ClearinghousePollStatus extends Command
{
    protected $signature = 'clearinghouse:poll-status {--tenant= : Limit to a single tenant id}';
    protected $description = 'Poll the clearinghouse for acknowledgments on in-flight 837P batches.';

    public function handle(ClearinghouseGatewayFactory $factory): int
    {
        $query = ClearinghouseConfig::active();
        if ($tid = $this->option('tenant')) {
            $query->where('tenant_id', (int) $tid);
        }

        foreach ($query->get() as $cfg) {
            $gateway = $factory->resolve($cfg->adapter);
            try {
                $ackCount = $gateway->fetchAcknowledgments($cfg);
                $this->info("Tenant {$cfg->tenant_id} [{$cfg->adapter}]: {$ackCount} acknowledgment(s) fetched.");

                // For each in-flight batch, poll for status updates
                $batches = EdiBatch::where('tenant_id', $cfg->tenant_id)
                    ->whereIn('status', ['submitted', 'pending'])
                    ->where('created_at', '>=', now()->subDays(30))
                    ->limit(50)
                    ->get();

                foreach ($batches as $batch) {
                    try {
                        $gateway->pollStatus($batch, $cfg);
                    } catch (\Throwable $e) {
                        $this->warn("  Batch {$batch->id}: {$e->getMessage()}");
                    }
                }
            } catch (\Throwable $e) {
                $this->warn("Tenant {$cfg->tenant_id}: {$e->getMessage()}");
            }
        }

        return self::SUCCESS;
    }
}
