<?php

// ─── FhirBulkExportService ───────────────────────────────────────────────────
// Phase 15.1 (MVP roadmap). Walks a tenant's participants, runs each FHIR
// mapper per supported resource type, and writes NDJSON files to
// storage/app/fhir-exports/{job_id}/{ResourceType}.ndjson.
//
// Complies with HL7 FHIR Bulk Data Access IG v2.0.0 (System / Group level
// $export). Output files are `application/fhir+ndjson` — one resource per
// line, no array wrapper.
//
// Cadence: this is intended to run inside a queue job. For small tenants
// (<200 participants) inline execution completes in seconds; for larger
// tenants the job progresses in batches and updates `progress_pct`.
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Services;

use App\Fhir\Mappers\AllergyIntoleranceMapper;
use App\Fhir\Mappers\AppointmentMapper;
use App\Fhir\Mappers\CarePlanMapper;
use App\Fhir\Mappers\ConditionMapper;
use App\Fhir\Mappers\DiagnosticReportMapper;
use App\Fhir\Mappers\EncounterMapper;
use App\Fhir\Mappers\ImmunizationMapper;
use App\Fhir\Mappers\MedicationRequestMapper;
use App\Fhir\Mappers\ObservationMapper;
use App\Fhir\Mappers\PatientMapper;
use App\Fhir\Mappers\ProcedureMapper;
use App\Models\Allergy;
use App\Models\Appointment;
use App\Models\CarePlan;
use App\Models\FhirBulkExportJob;
use App\Models\Immunization;
use App\Models\LabResult;
use App\Models\Medication;
use App\Models\Participant;
use App\Models\Problem;
use App\Models\Procedure;
use App\Models\Vital;
use Illuminate\Support\Facades\Storage;

class FhirBulkExportService
{
    public const DISK = 'local';
    public const SUPPORTED_RESOURCES = [
        'Patient', 'Observation', 'MedicationRequest', 'Condition',
        'AllergyIntolerance', 'CarePlan', 'Appointment',
        'Immunization', 'Procedure', 'Encounter', 'DiagnosticReport',
    ];

    public function run(FhirBulkExportJob $job): void
    {
        $job->update([
            'status'     => 'in_progress',
            'started_at' => now(),
        ]);

        try {
            $resources = $job->resourceTypesArray() ?: self::SUPPORTED_RESOURCES;
            $since     = $job->since;
            $tenantId  = $job->tenant_id;

            $dir = $this->jobDir($job);
            Storage::disk(self::DISK)->makeDirectory($dir);

            $manifest = [];
            $total = count($resources);
            $i = 0;

            foreach ($resources as $type) {
                $i++;
                $count = $this->exportResource($tenantId, $type, $since, $dir);
                if ($count > 0) {
                    $url = $this->fileUrlFor($job, $type);
                    $manifest[] = [
                        'type' => $type,
                        'url'  => $url,
                        'count' => $count,
                    ];
                }
                $job->update([
                    'progress_pct' => (int) round(($i / $total) * 100),
                ]);
            }

            $job->update([
                'status'       => 'complete',
                'progress_pct' => 100,
                'manifest_json'=> json_encode([
                    'transactionTime' => now()->toIso8601String(),
                    'request'         => '$export',
                    'requiresAccessToken' => true,
                    'output'          => $manifest,
                    'error'           => [],
                ]),
                'completed_at' => now(),
            ]);
        } catch (\Throwable $e) {
            $job->update([
                'status'        => 'failed',
                'error_message' => substr($e->getMessage(), 0, 500),
                'completed_at'  => now(),
            ]);
            throw $e;
        }
    }

    public function jobDir(FhirBulkExportJob $job): string
    {
        return "fhir-exports/tenant-{$job->tenant_id}/job-{$job->id}";
    }

    public function filePath(FhirBulkExportJob $job, string $resourceType): string
    {
        return $this->jobDir($job) . '/' . $resourceType . '.ndjson';
    }

    public function fullPath(FhirBulkExportJob $job, string $resourceType): string
    {
        return Storage::disk(self::DISK)->path($this->filePath($job, $resourceType));
    }

    private function fileUrlFor(FhirBulkExportJob $job, string $resourceType): string
    {
        return config('app.url') . "/fhir/R4/export-file/{$job->id}/{$resourceType}.ndjson";
    }

    // ── Per-resource export loops ───────────────────────────────────────────

    private function exportResource(int $tenantId, string $type, $since, string $dir): int
    {
        $handle = fopen(Storage::disk(self::DISK)->path($dir . '/' . $type . '.ndjson'), 'w');
        if ($handle === false) return 0;

        $count = 0;
        try {
            switch ($type) {
                case 'Patient':
                    $q = Participant::where('tenant_id', $tenantId);
                    if ($since) $q->where('updated_at', '>=', $since);
                    $q->chunk(200, function ($batch) use (&$count, $handle) {
                        foreach ($batch as $p) {
                            fwrite($handle, json_encode(PatientMapper::toFhir($p)) . "\n");
                            $count++;
                        }
                    });
                    break;
                case 'Observation':
                    $q = Vital::whereHas('participant', fn ($p) => $p->where('tenant_id', $tenantId));
                    if ($since) $q->where('updated_at', '>=', $since);
                    $q->chunk(200, function ($batch) use (&$count, $handle) {
                        foreach ($batch as $v) {
                            foreach (ObservationMapper::toFhirCollection($v) as $obs) {
                                fwrite($handle, json_encode($obs) . "\n");
                                $count++;
                            }
                        }
                    });
                    break;
                case 'MedicationRequest':
                    $q = Medication::whereHas('participant', fn ($p) => $p->where('tenant_id', $tenantId));
                    if ($since) $q->where('updated_at', '>=', $since);
                    $q->chunk(200, function ($batch) use (&$count, $handle) {
                        foreach ($batch as $m) {
                            fwrite($handle, json_encode(MedicationRequestMapper::toFhir($m)) . "\n");
                            $count++;
                        }
                    });
                    break;
                case 'Condition':
                    $q = Problem::whereHas('participant', fn ($p) => $p->where('tenant_id', $tenantId));
                    if ($since) $q->where('updated_at', '>=', $since);
                    $q->chunk(200, function ($batch) use (&$count, $handle) {
                        foreach ($batch as $p) {
                            fwrite($handle, json_encode(ConditionMapper::toFhir($p)) . "\n");
                            $count++;
                        }
                    });
                    break;
                case 'AllergyIntolerance':
                    $q = Allergy::whereHas('participant', fn ($p) => $p->where('tenant_id', $tenantId));
                    if ($since) $q->where('updated_at', '>=', $since);
                    $q->chunk(200, function ($batch) use (&$count, $handle) {
                        foreach ($batch as $a) {
                            fwrite($handle, json_encode(AllergyIntoleranceMapper::toFhir($a)) . "\n");
                            $count++;
                        }
                    });
                    break;
                case 'CarePlan':
                    $q = CarePlan::whereHas('participant', fn ($p) => $p->where('tenant_id', $tenantId));
                    if ($since) $q->where('updated_at', '>=', $since);
                    $q->chunk(200, function ($batch) use (&$count, $handle) {
                        foreach ($batch as $cp) {
                            fwrite($handle, json_encode(CarePlanMapper::toFhir($cp)) . "\n");
                            $count++;
                        }
                    });
                    break;
                case 'Appointment':
                case 'Encounter':
                    $q = Appointment::whereHas('participant', fn ($p) => $p->where('tenant_id', $tenantId));
                    if ($since) $q->where('updated_at', '>=', $since);
                    $q->chunk(200, function ($batch) use (&$count, $handle, $type) {
                        foreach ($batch as $a) {
                            $payload = $type === 'Encounter'
                                ? EncounterMapper::toFhir($a)
                                : AppointmentMapper::toFhir($a);
                            fwrite($handle, json_encode($payload) . "\n");
                            $count++;
                        }
                    });
                    break;
                case 'Immunization':
                    $q = Immunization::whereHas('participant', fn ($p) => $p->where('tenant_id', $tenantId));
                    if ($since) $q->where('updated_at', '>=', $since);
                    $q->chunk(200, function ($batch) use (&$count, $handle) {
                        foreach ($batch as $im) {
                            fwrite($handle, json_encode(ImmunizationMapper::toFhir($im)) . "\n");
                            $count++;
                        }
                    });
                    break;
                case 'Procedure':
                    $q = Procedure::whereHas('participant', fn ($p) => $p->where('tenant_id', $tenantId));
                    if ($since) $q->where('updated_at', '>=', $since);
                    $q->chunk(200, function ($batch) use (&$count, $handle) {
                        foreach ($batch as $pr) {
                            fwrite($handle, json_encode(ProcedureMapper::toFhir($pr)) . "\n");
                            $count++;
                        }
                    });
                    break;
                case 'DiagnosticReport':
                    $q = LabResult::whereHas('participant', fn ($p) => $p->where('tenant_id', $tenantId));
                    if ($since) $q->where('updated_at', '>=', $since);
                    $q->chunk(200, function ($batch) use (&$count, $handle) {
                        foreach ($batch as $lab) {
                            fwrite($handle, json_encode(DiagnosticReportMapper::toFhir($lab)) . "\n");
                            $count++;
                        }
                    });
                    break;
            }
        } finally {
            fclose($handle);
        }

        // Remove empty file so the manifest stays clean
        if ($count === 0) {
            Storage::disk(self::DISK)->delete($dir . '/' . $type . '.ndjson');
        }
        return $count;
    }
}
