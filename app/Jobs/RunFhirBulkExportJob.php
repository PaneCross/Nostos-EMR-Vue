<?php

// ─── RunFhirBulkExportJob ────────────────────────────────────────────────────
// Phase 15.1. Queue-backed worker that drives FhirBulkExportService against
// a FhirBulkExportJob row. Under the `sync` queue driver (tests) the work
// happens inline; under `database`/`redis` it runs async per the HL7 Bulk
// Data Access spec.
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Jobs;

use App\Models\AuditLog;
use App\Models\FhirBulkExportJob;
use App\Services\FhirBulkExportService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class RunFhirBulkExportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;     // idempotency is tricky with partial file writes
    public int $timeout = 600; // 10 min max per job

    public function __construct(public readonly int $jobId) {
        $this->onQueue('fhir-bulk-export');
    }

    public function handle(FhirBulkExportService $svc): void
    {
        $job = FhirBulkExportJob::find($this->jobId);
        if (! $job || $job->isTerminal()) return;

        try {
            $svc->run($job);
            AuditLog::record(
                action: 'fhir.bulk_export_complete',
                tenantId: $job->tenant_id,
                resourceType: 'fhir_bulk_export_job',
                resourceId: $job->id,
                description: 'FHIR Bulk Data Access export complete',
            );
        } catch (\Throwable $e) {
            AuditLog::record(
                action: 'fhir.bulk_export_failed',
                tenantId: $job->tenant_id,
                resourceType: 'fhir_bulk_export_job',
                resourceId: $job->id,
                description: 'FHIR Bulk Data Access export failed: ' . substr($e->getMessage(), 0, 200),
            );
            throw $e;
        }
    }
}
