<?php

// ─── EhiExportService ─────────────────────────────────────────────────────────
// 21st Century Cures Act § 4004 : Electronic Health Information (EHI) Export.
// Generates a ZIP archive containing all FHIR R4 resources + non-FHIR clinical
// data for a participant, suitable for patient access requests.
//
// Export contents:
//   fhir/                : FHIR R4 resources as individual JSON files
//     Patient.json
//     Observations.json  (vitals + SDOH)
//     Conditions.json
//     MedicationRequests.json
//     AllergyIntolerances.json
//     CarePlans.json
//     Immunizations.json
//     Procedures.json
//     Appointments.json
//   clinical/            : non-FHIR clinical data (JSON arrays)
//     clinical_notes.json
//     assessments.json
//     adl_records.json
//     sdrs.json
//     incidents.json
//   manifest.json        : export metadata (participant info, timestamps, content summary)
//
// The ZIP is written to storage/app/ehi_exports/{exportId}_{mrn}.zip.
// The EhiExport record tracks the token, file_path, status, and expiry.
//
// Token: 64-char hex (bin2hex(random_bytes(32))), single-use, 24h TTL.
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Services;

use App\Fhir\Mappers\AllergyIntoleranceMapper;
use App\Fhir\Mappers\AppointmentMapper;
use App\Fhir\Mappers\CarePlanMapper;
use App\Fhir\Mappers\ConditionMapper;
use App\Fhir\Mappers\ImmunizationMapper;
use App\Fhir\Mappers\MedicationRequestMapper;
use App\Fhir\Mappers\ObservationMapper;
use App\Fhir\Mappers\PatientMapper;
use App\Fhir\Mappers\ProcedureMapper;
use App\Fhir\Mappers\SdohObservationMapper;
use App\Models\Allergy;
use App\Models\Appointment;
use App\Models\Assessment;
use App\Models\CarePlan;
use App\Models\ClinicalNote;
use App\Models\EhiExport;
use App\Models\Immunization;
use App\Models\Medication;
use App\Models\Participant;
use App\Models\Problem;
use App\Models\Procedure;
use App\Models\Sdr;
use App\Models\SocialDeterminant;
use App\Models\Vital;
use App\Models\AdlRecord;
use App\Models\Incident;
use Illuminate\Support\Facades\Storage;
use ZipArchive;

class EhiExportService
{
    /**
     * Generate a complete EHI export ZIP for a participant.
     * Creates an EhiExport record and the physical ZIP file.
     *
     * @param  Participant $participant  The participant to export
     * @param  mixed       $requestedBy  The user who initiated the export
     * @return EhiExport  The created export record (status='ready')
     */
    public function generate(Participant $participant, $requestedBy): EhiExport
    {
        $token = bin2hex(random_bytes(32)); // 64-char hex
        $dir   = storage_path('app/ehi_exports');

        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $fileName = "ehi_export_{$participant->mrn}_{$token}.zip";
        $filePath = "ehi_exports/{$fileName}";
        $fullPath = storage_path("app/{$filePath}");

        $zip = new ZipArchive();
        $zip->open($fullPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        // ── FHIR R4 resources ─────────────────────────────────────────────────

        // Patient
        $zip->addFromString(
            'fhir/Patient.json',
            json_encode(PatientMapper::toFhir($participant), JSON_PRETTY_PRINT)
        );

        // Observations : vitals
        $vitals = Vital::where('participant_id', $participant->id)
            ->orderBy('recorded_at', 'desc')->limit(500)->get();
        $vitalsObs = [];
        foreach ($vitals as $vital) {
            foreach (ObservationMapper::toFhirCollection($vital) as $obs) {
                $vitalsObs[] = $obs;
            }
        }

        // Observations : SDOH screenings
        $screenings = SocialDeterminant::where('participant_id', $participant->id)
            ->orderBy('assessed_at', 'desc')->get();
        $sdohObs = [];
        foreach ($screenings as $screening) {
            foreach (SdohObservationMapper::toFhirCollection($screening) as $obs) {
                $sdohObs[] = $obs;
            }
        }

        $zip->addFromString(
            'fhir/Observations.json',
            json_encode(['vitals' => $vitalsObs, 'social_history' => $sdohObs], JSON_PRETTY_PRINT)
        );

        // Conditions (problem list)
        $problems = Problem::where('participant_id', $participant->id)
            ->where('tenant_id', $participant->tenant_id)
            ->orderBy('onset_date', 'desc')->get();
        $zip->addFromString(
            'fhir/Conditions.json',
            json_encode($problems->map(fn ($p) => ConditionMapper::toFhir($p))->all(), JSON_PRETTY_PRINT)
        );

        // MedicationRequests
        $meds = Medication::where('participant_id', $participant->id)
            ->where('tenant_id', $participant->tenant_id)
            ->orderBy('prescribed_date', 'desc')->get();
        $zip->addFromString(
            'fhir/MedicationRequests.json',
            json_encode($meds->map(fn ($m) => MedicationRequestMapper::toFhir($m))->all(), JSON_PRETTY_PRINT)
        );

        // AllergyIntolerances
        $allergies = Allergy::where('participant_id', $participant->id)
            ->where('tenant_id', $participant->tenant_id)->get();
        $zip->addFromString(
            'fhir/AllergyIntolerances.json',
            json_encode($allergies->map(fn ($a) => AllergyIntoleranceMapper::toFhir($a))->all(), JSON_PRETTY_PRINT)
        );

        // CarePlans
        $carePlans = CarePlan::where('participant_id', $participant->id)
            ->where('tenant_id', $participant->tenant_id)
            ->with('goals')
            ->orderBy('effective_date', 'desc')->get();
        $zip->addFromString(
            'fhir/CarePlans.json',
            json_encode($carePlans->map(fn ($cp) => CarePlanMapper::toFhir($cp))->all(), JSON_PRETTY_PRINT)
        );

        // Immunizations
        $immunizations = Immunization::where('participant_id', $participant->id)
            ->where('tenant_id', $participant->tenant_id)
            ->orderBy('administered_date', 'desc')->get();
        $zip->addFromString(
            'fhir/Immunizations.json',
            json_encode($immunizations->map(fn ($i) => ImmunizationMapper::toFhir($i))->all(), JSON_PRETTY_PRINT)
        );

        // Procedures
        $procedures = Procedure::where('participant_id', $participant->id)
            ->where('tenant_id', $participant->tenant_id)
            ->orderBy('performed_date', 'desc')->get();
        $zip->addFromString(
            'fhir/Procedures.json',
            json_encode($procedures->map(fn ($p) => ProcedureMapper::toFhir($p))->all(), JSON_PRETTY_PRINT)
        );

        // Appointments
        $appointments = Appointment::where('participant_id', $participant->id)
            ->where('tenant_id', $participant->tenant_id)
            ->orderBy('scheduled_start', 'desc')->limit(200)->get();
        $zip->addFromString(
            'fhir/Appointments.json',
            json_encode($appointments->map(fn ($a) => AppointmentMapper::toFhir($a))->all(), JSON_PRETTY_PRINT)
        );

        // ── Non-FHIR clinical data ────────────────────────────────────────────

        // Clinical notes (exclude PHI-sensitive HIPAA psychotherapy notes from export per 42 CFR Part 2)
        $notes = ClinicalNote::where('participant_id', $participant->id)
            ->where('tenant_id', $participant->tenant_id)
            ->orderBy('created_at', 'desc')->get();
        $zip->addFromString(
            'clinical/clinical_notes.json',
            json_encode($notes->map(fn ($n) => [
                'id'           => $n->id,
                'note_type'    => $n->note_type,
                'visit_type'   => $n->visit_type,
                'department'   => $n->department,
                'status'       => $n->status,
                'content'      => $n->content,
                'signed_at'    => $n->signed_at?->toIso8601String(),
                'created_at'   => $n->created_at->toIso8601String(),
            ])->all(), JSON_PRETTY_PRINT)
        );

        // Assessments
        $assessments = Assessment::where('participant_id', $participant->id)
            ->where('tenant_id', $participant->tenant_id)
            ->orderBy('completed_at', 'desc')->get();
        $zip->addFromString(
            'clinical/assessments.json',
            json_encode($assessments->map(fn ($a) => [
                'id'              => $a->id,
                'assessment_type' => $a->assessment_type,
                'responses'       => $a->responses,
                'score'           => $a->score,
                'completed_at'    => $a->completed_at?->toIso8601String(),
            ])->all(), JSON_PRETTY_PRINT)
        );

        // ADL records
        $adlRecords = AdlRecord::where('participant_id', $participant->id)
            ->orderBy('recorded_at', 'desc')->limit(500)->get();
        $zip->addFromString(
            'clinical/adl_records.json',
            json_encode($adlRecords->map(fn ($a) => [
                'id'          => $a->id,
                'adl_type'    => $a->adl_type,
                'score'       => $a->score,
                'notes'       => $a->notes,
                'recorded_at' => $a->recorded_at->toIso8601String(),
            ])->all(), JSON_PRETTY_PRINT)
        );

        // SDRs
        $sdrs = Sdr::where('participant_id', $participant->id)
            ->where('tenant_id', $participant->tenant_id)
            ->orderBy('submitted_at', 'desc')->get();
        $zip->addFromString(
            'clinical/sdrs.json',
            json_encode($sdrs->map(fn ($s) => [
                'id'                  => $s->id,
                'sdr_type'            => $s->sdr_type,
                'description'         => $s->description,
                'assigned_department' => $s->assigned_department,
                'status'              => $s->status,
                'submitted_at'        => $s->submitted_at->toIso8601String(),
                'due_at'              => $s->due_at->toIso8601String(),
            ])->all(), JSON_PRETTY_PRINT)
        );

        // Incidents (participant-related only)
        $incidents = Incident::where('participant_id', $participant->id)
            ->where('tenant_id', $participant->tenant_id)
            ->orderBy('occurred_at', 'desc')->get();
        $zip->addFromString(
            'clinical/incidents.json',
            json_encode($incidents->map(fn ($i) => [
                'id'            => $i->id,
                'incident_type' => $i->incident_type,
                'description'   => $i->description,
                'severity'      => $i->severity,
                'status'        => $i->status,
                'occurred_at'   => $i->occurred_at->toIso8601String(),
            ])->all(), JSON_PRETTY_PRINT)
        );

        // ── FHIR R4 Bundle ────────────────────────────────────────────────────
        // Phase 5 (MVP roadmap): add a proper FHIR R4 Bundle (type=collection)
        // with every mapped resource as an entry. This is the format expected
        // by ONC HTI-1 EHI export consumers and SMART on FHIR apps.

        $zip->addFromString(
            'fhir/Bundle.json',
            json_encode($this->buildFhirBundle(
                patient:       PatientMapper::toFhir($participant),
                vitalsObs:     $vitalsObs,
                sdohObs:       $sdohObs,
                problems:      $problems,
                meds:          $meds,
                allergies:     $allergies,
                carePlans:     $carePlans,
                immunizations: $immunizations,
                procedures:    $procedures,
                appointments:  $appointments,
            ), JSON_PRETTY_PRINT)
        );

        // ── Export manifest ───────────────────────────────────────────────────

        $zip->addFromString('manifest.json', json_encode([
            'export_version'   => '1.0',
            'generated_at'     => now()->toIso8601String(),
            'participant_mrn'  => $participant->mrn,
            'participant_id'   => $participant->id,
            'tenant_id'        => $participant->tenant_id,
            'exported_by'      => [
                'user_id'    => $requestedBy->id,
                'department' => $requestedBy->department,
            ],
            'contents' => [
                'fhir_patient'              => 1,
                'fhir_observations'         => count($vitalsObs) + count($sdohObs),
                'fhir_conditions'           => $problems->count(),
                'fhir_medication_requests'  => $meds->count(),
                'fhir_allergies'            => $allergies->count(),
                'fhir_care_plans'           => $carePlans->count(),
                'fhir_immunizations'        => $immunizations->count(),
                'fhir_procedures'           => $procedures->count(),
                'fhir_appointments'         => $appointments->count(),
                'clinical_notes'            => $notes->count(),
                'assessments'               => $assessments->count(),
                'adl_records'               => $adlRecords->count(),
                'sdrs'                      => $sdrs->count(),
                'incidents'                 => $incidents->count(),
            ],
        ], JSON_PRETTY_PRINT));

        $zip->close();

        return EhiExport::create([
            'participant_id'      => $participant->id,
            'tenant_id'           => $participant->tenant_id,
            'requested_by_user_id'=> $requestedBy->id,
            'token'               => $token,
            'file_path'           => $filePath,
            'status'              => 'ready',
            'expires_at'          => now()->addHours(24),
        ]);
    }

    /**
     * Assemble a FHIR R4 Bundle (type=collection) from the already-mapped resources.
     * One entry per resource, in USCDI-priority order.
     * Phase 5 (MVP roadmap).
     */
    public function buildFhirBundle(
        array $patient,
        array $vitalsObs,
        array $sdohObs,
        $problems,
        $meds,
        $allergies,
        $carePlans,
        $immunizations,
        $procedures,
        $appointments,
    ): array {
        $entries = [];
        $addEntry = function (array $resource) use (&$entries) {
            $ref = ($resource['resourceType'] ?? 'Resource') . '/' . ($resource['id'] ?? '');
            $entries[] = [
                'fullUrl' => 'urn:local:' . $ref,
                'resource' => $resource,
            ];
        };

        // Patient is always first per USCDI guidance.
        $addEntry($patient);

        foreach ($vitalsObs as $obs)      $addEntry($obs);
        foreach ($sdohObs as $obs)        $addEntry($obs);
        foreach ($problems as $p)         $addEntry(ConditionMapper::toFhir($p));
        foreach ($allergies as $a)        $addEntry(AllergyIntoleranceMapper::toFhir($a));
        foreach ($meds as $m)             $addEntry(MedicationRequestMapper::toFhir($m));
        foreach ($immunizations as $i)    $addEntry(ImmunizationMapper::toFhir($i));
        foreach ($procedures as $p)       $addEntry(ProcedureMapper::toFhir($p));
        foreach ($carePlans as $cp)       $addEntry(CarePlanMapper::toFhir($cp));
        foreach ($appointments as $appt)  $addEntry(AppointmentMapper::toFhir($appt));

        return [
            'resourceType' => 'Bundle',
            'id'           => 'ehi-export-' . (string) $patient['id'] . '-' . now()->format('YmdHis'),
            'type'         => 'collection',
            'timestamp'    => now()->toIso8601String(),
            'total'        => count($entries),
            'entry'        => $entries,
        ];
    }
}
