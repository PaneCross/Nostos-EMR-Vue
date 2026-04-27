<?php

// ─── FhirBulkExportController ────────────────────────────────────────────────
// Phase 15.1 (MVP roadmap). Endpoints implementing HL7 FHIR Bulk Data Access
// IG v2.0.0.
//
// Flow:
//   1. POST /fhir/R4/$export (Accept: application/fhir+json, Prefer: respond-async)
//      → 202 Accepted; Content-Location header → status URL
//   2. GET /fhir/R4/export-status/{jobId}
//      → 202 while in_progress (with X-Progress header)
//      → 200 with manifest JSON when complete
//      → 500 OperationOutcome if failed
//   3. GET /fhir/R4/export-file/{jobId}/{ResourceType}.ndjson
//      → NDJSON stream (application/fhir+ndjson)
//   4. DELETE /fhir/R4/export-status/{jobId}
//      → cancels / cleans up
//
// Scope: system/*.read (or the specific type-level SMART scope for each
// requested type). Tenant-scoped by the Bearer token.
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Http\Controllers;

use App\Jobs\RunFhirBulkExportJob;
use App\Models\ApiToken;
use App\Models\AuditLog;
use App\Models\FhirBulkExportJob;
use App\Services\FhirBulkExportService;
use App\Services\PhiDisclosureService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class FhirBulkExportController extends Controller
{
    public function __construct(
        private FhirBulkExportService $svc,
        private PhiDisclosureService $disclosures,
    ) {}

    // ── POST /fhir/R4/$export ────────────────────────────────────────────────
    public function export(Request $request)
    {
        $token = $request->attributes->get('fhir_token');
        if (! $token instanceof ApiToken) {
            return $this->opOutcome(401, 'security', 'Missing or invalid token.');
        }
        // Bulk requires at least one system/*.read-class scope on the token
        if (! $token->hasScope('patient.read') && ! $token->hasScope('observation.read')) {
            return $this->opOutcome(403, 'forbidden',
                'Bulk export requires system/*.read or equivalent FHIR-resource scope.');
        }

        $validated = $request->validate([
            '_type'          => 'nullable|string',
            '_since'         => 'nullable|string',
            '_outputFormat'  => 'nullable|in:application/fhir+ndjson,ndjson,fhir+ndjson',
        ]);

        $types = $validated['_type'] ?? null;
        if ($types) {
            $requested = array_map('trim', explode(',', $types));
            $invalid = array_values(array_diff($requested, FhirBulkExportService::SUPPORTED_RESOURCES));
            if (! empty($invalid)) {
                return $this->opOutcome(400, 'invalid',
                    'Unsupported _type requested: ' . implode(', ', $invalid));
            }
            $typesField = implode('|', $requested);
        } else {
            $typesField = null;
        }

        $since = null;
        if (! empty($validated['_since'])) {
            try {
                $since = \Illuminate\Support\Carbon::parse($validated['_since']);
            } catch (\Throwable) {
                return $this->opOutcome(400, 'invalid', '_since must be an ISO 8601 instant.');
            }
        }

        $prefer = strtolower((string) $request->header('Prefer', ''));
        // HL7 Bulk Data requires "Prefer: respond-async"; if missing, 400 per spec
        if (! str_contains($prefer, 'respond-async')) {
            return $this->opOutcome(400, 'invalid',
                'The Prefer header must be "respond-async" per HL7 Bulk Data Access IG.');
        }

        $job = FhirBulkExportJob::create([
            'tenant_id'      => $token->tenant_id,
            'api_token_id'   => $token->id,
            'status'         => 'accepted',
            'resource_types' => $typesField,
            'since'          => $since,
            'output_format'  => 'application/fhir+ndjson',
            'progress_pct'   => 0,
        ]);

        AuditLog::record(
            action: 'fhir.bulk_export_requested',
            tenantId: $token->tenant_id,
            userId: $token->user_id,
            resourceType: 'fhir_bulk_export_job',
            resourceId: $job->id,
            description: 'FHIR $export requested: types=' . ($typesField ?? '*')
                         . ' since=' . ($since?->toIso8601String() ?? 'none'),
        );

        dispatch(new RunFhirBulkExportJob($job->id));

        $statusUrl = rtrim(config('app.url'), '/') . '/fhir/R4/export-status/' . $job->id;

        return response('', 202, [
            'Content-Location' => $statusUrl,
            'Content-Type'     => 'application/fhir+json',
        ]);
    }

    // ── GET /fhir/R4/export-status/{jobId} ───────────────────────────────────
    public function status(Request $request, int $jobId)
    {
        $token = $request->attributes->get('fhir_token');
        if (! $token instanceof ApiToken) {
            return $this->opOutcome(401, 'security', 'Missing or invalid token.');
        }
        $job = FhirBulkExportJob::forTenant($token->tenant_id)->find($jobId);
        if (! $job) return $this->opOutcome(404, 'not-found', 'Export job not found.');

        return match ($job->status) {
            'accepted', 'in_progress' => response('', 202, [
                'X-Progress'  => 'in-progress, ' . $job->progress_pct . '%',
                'Retry-After' => '5',
            ]),
            'complete' => response($job->manifest_json, 200, [
                'Content-Type' => 'application/fhir+json',
                'Expires'      => now()->addDay()->toRfc7231String(),
            ]),
            'cancelled' => $this->opOutcome(404, 'not-found', 'Export cancelled.'),
            default => $this->opOutcome(500, 'exception',
                'Export failed: ' . ($job->error_message ?? 'unknown error')),
        };
    }

    // ── GET /fhir/R4/export-file/{jobId}/{ResourceType}.ndjson ───────────────
    public function file(Request $request, int $jobId, string $resourceFile)
    {
        $token = $request->attributes->get('fhir_token');
        if (! $token instanceof ApiToken) {
            return $this->opOutcome(401, 'security', 'Missing or invalid token.');
        }
        $job = FhirBulkExportJob::forTenant($token->tenant_id)->find($jobId);
        if (! $job || $job->status !== 'complete') {
            return $this->opOutcome(404, 'not-found', 'Export file not available.');
        }

        if (! str_ends_with($resourceFile, '.ndjson')) {
            return $this->opOutcome(400, 'invalid', 'Expected .ndjson filename.');
        }
        $type = substr($resourceFile, 0, -7);
        if (! in_array($type, FhirBulkExportService::SUPPORTED_RESOURCES, true)) {
            return $this->opOutcome(404, 'not-found', 'Unsupported resource type.');
        }

        $path = $this->svc->filePath($job, $type);
        if (! Storage::disk(FhirBulkExportService::DISK)->exists($path)) {
            return $this->opOutcome(404, 'not-found', 'No data was generated for this resource type.');
        }

        $fullPath = Storage::disk(FhirBulkExportService::DISK)->path($path);

        // Phase Q2 : HIPAA §164.528 Accounting of Disclosures. Record one
        // disclosure per distinct participant referenced by this file.
        $this->recordBulkDisclosures($fullPath, $type, $token, $request, $job->id);

        return new StreamedResponse(function () use ($fullPath) {
            readfile($fullPath);
        }, 200, [
            'Content-Type'        => 'application/fhir+ndjson',
            'Content-Disposition' => 'attachment; filename="' . $type . '.ndjson"',
        ]);
    }

    // ── DELETE /fhir/R4/export-status/{jobId} ────────────────────────────────
    public function cancel(Request $request, int $jobId)
    {
        $token = $request->attributes->get('fhir_token');
        if (! $token instanceof ApiToken) {
            return $this->opOutcome(401, 'security', 'Missing or invalid token.');
        }
        $job = FhirBulkExportJob::forTenant($token->tenant_id)->find($jobId);
        if (! $job) return $this->opOutcome(404, 'not-found', 'Export job not found.');

        if (! $job->isTerminal()) {
            $job->update(['status' => 'cancelled', 'completed_at' => now()]);
        }
        Storage::disk(FhirBulkExportService::DISK)->deleteDirectory($this->svc->jobDir($job));

        AuditLog::record(
            action: 'fhir.bulk_export_cancelled',
            tenantId: $token->tenant_id,
            userId: $token->user_id,
            resourceType: 'fhir_bulk_export_job',
            resourceId: $job->id,
            description: 'FHIR $export cancelled',
        );

        return response('', 202);
    }

    /**
     * Phase Q2 : extract distinct participant IDs from an NDJSON file and
     * record one PhiDisclosure per (participant, file) pair.
     */
    private function recordBulkDisclosures(string $fullPath, string $resourceType, ApiToken $token, Request $request, int $jobId): void
    {
        $participantIds = [];
        $fh = @fopen($fullPath, 'rb');
        if (! $fh) return;
        try {
            while (($line = fgets($fh)) !== false) {
                $row = json_decode($line, true);
                if (! is_array($row)) continue;
                if ($resourceType === 'Patient') {
                    if (! empty($row['id']) && is_numeric($row['id'])) {
                        $participantIds[(int) $row['id']] = true;
                    }
                } else {
                    $ref = $row['subject']['reference'] ?? ($row['patient']['reference'] ?? null);
                    if ($ref && preg_match('#Patient/(\d+)#', $ref, $m)) {
                        $participantIds[(int) $m[1]] = true;
                    }
                }
                if (count($participantIds) > 1000) break; // safety cap per file
            }
        } finally {
            fclose($fh);
        }
        $clientName = $token->name ?: 'FHIR Bulk Export client';
        foreach (array_keys($participantIds) as $pid) {
            $this->disclosures->record(
                tenantId: $token->tenant_id,
                participantId: $pid,
                recipientType: 'other',
                recipientName: $clientName,
                purpose: 'tpo',
                method: 'api',
                recordsDescribed: "FHIR Bulk Export {$resourceType}.ndjson (job #{$jobId})",
                disclosedByUserId: $token->user_id,
            );
        }
    }

    private function opOutcome(int $status, string $code, string $message)
    {
        return response()->json([
            'resourceType' => 'OperationOutcome',
            'issue' => [[
                'severity'    => $status >= 500 ? 'fatal' : 'error',
                'code'        => $code,
                'diagnostics' => $message,
            ]],
        ], $status, ['Content-Type' => 'application/fhir+json']);
    }
}
