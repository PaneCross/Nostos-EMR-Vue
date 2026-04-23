<?php

// ─── DataImportService ───────────────────────────────────────────────────────
// Phase 15.4. CSV-to-EMR-model ingest pipeline. Huge competitive advantage
// when displacing an incumbent EMR — "here's your CSV, we'll load it."
//
// Two-phase flow:
//   1. parseCsv($dataImport)   → validates headers, counts rows, stores errors
//   2. commit($dataImport, $mapping) → inserts rows in a DB transaction
//
// Entity-specific mappers translate CSV columns into Model fillables.
// Participants are created via Participant::create so MrnService auto-assigns
// an MRN. Downstream entities (problems/allergies/meds) require participant_id
// which the importer tries to resolve by existing participant's MRN column.
//
// Errors are recorded per-row in errors_json; commit still runs for valid rows.
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Services;

use App\Models\Allergy;
use App\Models\DataImport;
use App\Models\Medication;
use App\Models\Participant;
use App\Models\Problem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class DataImportService
{
    public const TEMPLATE_HEADERS = [
        'participants' => ['first_name', 'last_name', 'dob', 'gender', 'enrollment_status',
            'enrollment_date', 'medicare_id', 'medicaid_id', 'primary_language'],
        'problems'     => ['mrn', 'icd10_code', 'icd10_description', 'snomed_code', 'onset_date', 'status'],
        'allergies'    => ['mrn', 'allergy_type', 'allergen_name', 'rxnorm_code', 'reaction_description', 'severity'],
        'medications'  => ['mrn', 'drug_name', 'rxnorm_code', 'dose', 'dose_unit', 'route', 'frequency',
            'is_prn', 'prescribed_date', 'start_date', 'status'],
    ];

    public function template(string $entity): string
    {
        $cols = self::TEMPLATE_HEADERS[$entity] ?? [];
        return implode(',', $cols) . "\n";
    }

    public function parseCsv(DataImport $import): array
    {
        $path = Storage::disk('local')->path($import->stored_path);
        if (! file_exists($path)) throw new \RuntimeException("CSV not found: {$import->stored_path}");

        $handle = fopen($path, 'r');
        $header = fgetcsv($handle);
        if ($header === false) {
            fclose($handle);
            throw new \RuntimeException('Empty CSV.');
        }

        $expected = self::TEMPLATE_HEADERS[$import->entity] ?? [];
        $missing  = array_diff($expected, $header);

        $errors = [];
        $rowCount = 0;
        $preview  = [];

        while (($row = fgetcsv($handle)) !== false) {
            $rowCount++;
            if (count($row) !== count($header)) {
                $errors[] = ['row' => $rowCount, 'message' => 'Column count mismatch.'];
                continue;
            }
            $record = array_combine($header, $row);
            if (count($preview) < 10) $preview[] = $record;
        }
        fclose($handle);

        if (! empty($missing)) {
            $errors[] = ['row' => 0, 'message' => 'Missing required columns: ' . implode(', ', $missing)];
        }

        $import->update([
            'parsed_row_count' => $rowCount,
            'error_row_count'  => count($errors),
            'errors_json'      => $errors,
            'column_mapping'   => array_combine($header, $header), // identity default
            'staged_at'        => now(),
        ]);

        return [
            'row_count' => $rowCount,
            'errors'    => $errors,
            'preview'   => $preview,
            'headers'   => $header,
            'expected_headers' => $expected,
        ];
    }

    public function commit(DataImport $import): array
    {
        if ($import->status !== 'staged') {
            throw new \RuntimeException("Import must be in 'staged' status. Current: {$import->status}");
        }

        $path = Storage::disk('local')->path($import->stored_path);
        $handle = fopen($path, 'r');
        $header = fgetcsv($handle);

        $inserted = 0;
        $errors = $import->errors_json ?? [];
        $rowNum = 0;

        DB::beginTransaction();
        try {
            while (($row = fgetcsv($handle)) !== false) {
                $rowNum++;
                if (count($row) !== count($header)) continue;
                $record = array_combine($header, $row);
                try {
                    $this->insertRow($import, $record);
                    $inserted++;
                } catch (\Throwable $e) {
                    $errors[] = [
                        'row'     => $rowNum,
                        'message' => substr($e->getMessage(), 0, 200),
                    ];
                }
            }
            $import->update([
                'status'              => 'committed',
                'committed_row_count' => $inserted,
                'error_row_count'     => count($errors),
                'errors_json'         => $errors,
                'committed_at'        => now(),
            ]);
            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            $import->update([
                'status'       => 'failed',
                'errors_json'  => array_merge($errors, [['row' => 0, 'message' => $e->getMessage()]]),
                'committed_at' => now(),
            ]);
            throw $e;
        } finally {
            fclose($handle);
        }

        return ['inserted' => $inserted, 'errors' => $errors];
    }

    private function insertRow(DataImport $import, array $record): void
    {
        match ($import->entity) {
            'participants' => $this->insertParticipant($import, $record),
            'problems'     => $this->insertProblem($import, $record),
            'allergies'    => $this->insertAllergy($import, $record),
            'medications'  => $this->insertMedication($import, $record),
            default        => throw new \InvalidArgumentException("Unsupported entity: {$import->entity}"),
        };
    }

    private function insertParticipant(DataImport $import, array $r): void
    {
        $sites = DB::table('shared_sites')->where('tenant_id', $import->tenant_id)->limit(1)->value('id');
        Participant::create([
            'tenant_id'         => $import->tenant_id,
            'site_id'           => $sites,
            'first_name'        => $r['first_name'] ?? '',
            'last_name'         => $r['last_name']  ?? '',
            'dob'               => $r['dob'] ?: null,
            'gender'            => $r['gender'] ?: 'unknown',
            'enrollment_status' => $r['enrollment_status'] ?: 'enrolled',
            'enrollment_date'   => $r['enrollment_date'] ?: null,
            'medicare_id'       => $r['medicare_id'] ?: null,
            'medicaid_id'       => $r['medicaid_id'] ?: null,
            'primary_language'  => $r['primary_language'] ?: 'English',
            'interpreter_needed'=> false,
            'nursing_facility_eligible' => true,
            'is_active'         => true,
        ]);
    }

    private function resolveParticipantId(int $tenantId, string $mrn): int
    {
        $id = Participant::where('tenant_id', $tenantId)->where('mrn', $mrn)->value('id');
        if (! $id) throw new \InvalidArgumentException("MRN not found in tenant: {$mrn}");
        return $id;
    }

    private function insertProblem(DataImport $import, array $r): void
    {
        Problem::create([
            'tenant_id'         => $import->tenant_id,
            'participant_id'    => $this->resolveParticipantId($import->tenant_id, $r['mrn']),
            'icd10_code'        => $r['icd10_code'] ?: null,
            'icd10_description' => $r['icd10_description'] ?: null,
            'snomed_code'       => $r['snomed_code'] ?: null,
            'onset_date'        => $r['onset_date'] ?: null,
            'status'            => $r['status'] ?: 'active',
        ]);
    }

    private function insertAllergy(DataImport $import, array $r): void
    {
        Allergy::create([
            'tenant_id'            => $import->tenant_id,
            'participant_id'       => $this->resolveParticipantId($import->tenant_id, $r['mrn']),
            'allergy_type'         => $r['allergy_type'] ?: 'drug',
            'allergen_name'        => $r['allergen_name'] ?: '',
            'rxnorm_code'          => $r['rxnorm_code'] ?: null,
            'reaction_description' => $r['reaction_description'] ?: null,
            'severity'             => $r['severity'] ?: 'moderate',
            'is_active'            => true,
        ]);
    }

    private function insertMedication(DataImport $import, array $r): void
    {
        Medication::create([
            'tenant_id'      => $import->tenant_id,
            'participant_id' => $this->resolveParticipantId($import->tenant_id, $r['mrn']),
            'drug_name'      => $r['drug_name'] ?: '',
            'rxnorm_code'    => $r['rxnorm_code'] ?: null,
            'dose'           => $r['dose'] ?: null,
            'dose_unit'      => $r['dose_unit'] ?: null,
            'route'          => $r['route'] ?: 'oral',
            'frequency'      => $r['frequency'] ?: 'daily',
            'is_prn'         => in_array(strtolower((string) ($r['is_prn'] ?? '')), ['1', 'true', 'yes', 'y']),
            'prescribed_date'=> $r['prescribed_date'] ?: now()->toDateString(),
            'start_date'     => $r['start_date'] ?: now()->toDateString(),
            'status'         => $r['status'] ?: 'active',
        ]);
    }
}
