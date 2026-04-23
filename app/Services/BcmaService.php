<?php

// ─── BcmaService ─────────────────────────────────────────────────────────────
// Phase B4 — Barcode Medication Administration.
// Verifies a scanned (participant_barcode, medication_barcode) pair against
// an eMAR record and records the scan on the record. Supports clinical
// override with reason (logs as a high-priority audit event + emits alert).
//
// Verify outcomes:
//   OK             — both scans match the record; scan timestamps written
//   OVERRIDE       — mismatch, but user provided override reason; audit+alert
//   MISSING_SCAN   — participant or med scan empty; record not updated (422)
//   MISMATCH       — mismatch, no override reason; record not updated (422)
//   NOT_SCANNABLE  — participant has no barcode_value yet (run backfill)
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Services;

use App\Models\AuditLog;
use App\Models\EmarRecord;
use App\Models\User;

class BcmaService
{
    public const OK            = 'ok';
    public const OVERRIDE      = 'override';
    public const MISSING_SCAN  = 'missing_scan';
    public const MISMATCH      = 'mismatch';
    public const NOT_SCANNABLE = 'not_scannable';

    public function __construct(private AlertService $alerts) {}

    /**
     * Verify scanned barcodes against the eMAR record.
     *
     * @param  EmarRecord $record
     * @param  string|null $scannedParticipantBarcode
     * @param  string|null $scannedMedBarcode
     * @param  User $user
     * @param  string|null $overrideReason  If both barcodes don't match, caller
     *                                      may still proceed by providing an
     *                                      override reason (≥10 chars).
     * @return array{status: string, expected?: array, scanned?: array}
     */
    public function verify(
        EmarRecord $record,
        ?string $scannedParticipantBarcode,
        ?string $scannedMedBarcode,
        User $user,
        ?string $overrideReason = null,
    ): array {
        $participant = $record->participant;
        $medication  = $record->medication;

        if (! $participant?->barcode_value) {
            return ['status' => self::NOT_SCANNABLE];
        }

        if (! $scannedParticipantBarcode || ! $scannedMedBarcode) {
            return ['status' => self::MISSING_SCAN];
        }

        $participantMatch = hash_equals($participant->barcode_value, trim($scannedParticipantBarcode));
        $medMatch         = $medication?->barcode_value
            ? hash_equals($medication->barcode_value, trim($scannedMedBarcode))
            : false;

        $now = now();

        if ($participantMatch && $medMatch) {
            // Clean scan — record both timestamps, no mismatch.
            $record->update([
                'barcode_scanned_participant_at' => $now,
                'barcode_scanned_med_at'         => $now,
                // Clear any prior override — shouldn't happen but defensive.
                'barcode_mismatch_overridden_by_user_id' => null,
                'barcode_override_reason_text'           => null,
            ]);
            AuditLog::record(
                action: 'emar.bcma_verified',
                tenantId: $record->tenant_id,
                userId: $user->id,
                resourceType: 'emar_record',
                resourceId: $record->id,
                description: "BCMA scan verified for eMAR record #{$record->id}.",
            );
            return ['status' => self::OK];
        }

        // Mismatch from here on. Override path:
        if (is_string($overrideReason) && strlen(trim($overrideReason)) >= 10) {
            $record->update([
                'barcode_scanned_participant_at'          => $now,
                'barcode_scanned_med_at'                  => $now,
                'barcode_mismatch_overridden_by_user_id'  => $user->id,
                'barcode_override_reason_text'            => trim($overrideReason),
            ]);
            AuditLog::record(
                action: 'emar.bcma_override',
                tenantId: $record->tenant_id,
                userId: $user->id,
                resourceType: 'emar_record',
                resourceId: $record->id,
                description: "BCMA mismatch OVERRIDDEN on eMAR #{$record->id}. "
                    . 'Participant match: ' . ($participantMatch ? 'Y' : 'N')
                    . ', Med match: ' . ($medMatch ? 'Y' : 'N') . '.',
                newValues: [
                    'participant_match' => $participantMatch,
                    'med_match'         => $medMatch,
                    'override_reason'   => trim($overrideReason),
                ],
            );
            // High-priority alert on every override (qa_compliance + pharmacy).
            $this->alerts->create([
                'tenant_id'          => $record->tenant_id,
                'participant_id'     => $record->participant_id,
                'source_module'      => 'emar',
                'alert_type'         => 'bcma_override',
                'severity'           => 'warning',
                'title'              => 'BCMA mismatch override',
                'message'            => "Nurse {$user->first_name} {$user->last_name} overrode a BCMA mismatch "
                    . "on eMAR #{$record->id} (participant_match="
                    . ($participantMatch ? 'Y' : 'N')
                    . ", med_match=" . ($medMatch ? 'Y' : 'N') . ').',
                'target_departments' => ['qa_compliance', 'pharmacy'],
                'metadata'           => [
                    'emar_record_id'    => $record->id,
                    'user_id'           => $user->id,
                    'participant_match' => $participantMatch,
                    'med_match'         => $medMatch,
                ],
            ]);
            return [
                'status'   => self::OVERRIDE,
                'expected' => [
                    'participant_barcode' => $participant->barcode_value,
                    'medication_barcode'  => $medication?->barcode_value,
                ],
                'scanned'  => [
                    'participant_barcode' => $scannedParticipantBarcode,
                    'medication_barcode'  => $scannedMedBarcode,
                ],
            ];
        }

        return [
            'status'   => self::MISMATCH,
            'expected' => [
                'participant_barcode' => $participant->barcode_value,
                'medication_barcode'  => $medication?->barcode_value,
            ],
            'scanned'  => [
                'participant_barcode' => $scannedParticipantBarcode,
                'medication_barcode'  => $scannedMedBarcode,
            ],
        ];
    }
}
