<?php

// ─── EnrollmentReconciliationService ──────────────────────────────────────────
// For each MmrRecord in a file, match to a local Participant by medicare_id
// and flag any of four discrepancy types:
//   1. CMS enrolled, local disenrolled
//   2. CMS disenrolled, locally enrolled
//   3. Capitation variance (received amount ≠ expected local capitation)
//   4. Retroactive adjustment (non-zero adjustment_amount)
//   + special case: unmatched MBI (no local participant for this MBI)
//
// Also identifies members CMS has that we don't have locally AT ALL for the
// period (generates synthetic open-discrepancy rows? no — those already are
// the "unmatched_mbi" discrepancy).
//
// Conversely: a "local enrolled, missing on MMR" gap is NOT a per-record
// discrepancy — it's a file-level summary reported alongside the worklist
// (see reconciliationSummary()).
//
// The service is intentionally pure on the MMR side — it only reads local
// roster, never writes to Participant.
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Services;

use App\Models\CapitationRecord;
use App\Models\MmrFile;
use App\Models\MmrRecord;
use App\Models\Participant;

class EnrollmentReconciliationService
{
    /**
     * Capitation variance threshold — absolute dollar delta between CMS-paid
     * capitation and the local expected capitation above which we flag a
     * discrepancy. Under this threshold we treat as rounding / acceptable drift.
     */
    public const CAPITATION_VARIANCE_THRESHOLD = 1.00;

    /**
     * Reconcile every MmrRecord in $file against the local Participant roster.
     * Returns the count of discrepancies flagged.
     */
    public function reconcileFile(MmrFile $file): int
    {
        $records = MmrRecord::where('mmr_file_id', $file->id)->get();
        if ($records->isEmpty()) return 0;

        // Build an MBI → Participant map (decrypts medicare_id on read).
        $participantsByMbi = Participant::where('tenant_id', $file->tenant_id)
            ->get(['id', 'medicare_id', 'enrollment_status', 'disenrollment_date', 'first_name', 'last_name', 'mrn'])
            ->mapWithKeys(fn (Participant $p) => [$p->medicare_id => $p])
            ->filter(fn ($_p, $mbi) => ! empty($mbi));

        // Expected capitation for the period (month_year = 'YYYY-MM').
        $periodYm = sprintf('%04d-%02d', $file->period_year, $file->period_month);
        $expectedCapByParticipant = CapitationRecord::where('tenant_id', $file->tenant_id)
            ->where('month_year', $periodYm)
            ->pluck('total_capitation', 'participant_id')
            ->map(fn ($v) => (float) $v)
            ->toArray();

        $discrepancyCount = 0;

        foreach ($records as $record) {
            $matched = $participantsByMbi[$record->medicare_id] ?? null;

            if (! $matched) {
                $record->update([
                    'matched_participant_id' => null,
                    'discrepancy_type'       => MmrRecord::DISC_UNMATCHED_MBI,
                    'discrepancy_note'       => 'No local participant with this Medicare ID.',
                ]);
                $discrepancyCount++;
                continue;
            }

            // Base match.
            $cmsStatus = strtolower($record->member_status);
            $cmsEnrolled = $cmsStatus === 'active';
            $localEnrolled = $matched->enrollment_status === 'enrolled';

            $disc = null;
            $note = null;

            if ($cmsEnrolled && ! $localEnrolled) {
                $disc = MmrRecord::DISC_CMS_ENROLLED_NOT_LOCAL;
                $note = 'CMS considers this member enrolled; local record is '
                    . $matched->enrollment_status . '.';
            } elseif (! $cmsEnrolled && $localEnrolled) {
                $disc = MmrRecord::DISC_CMS_DISENROLLED_LOCAL_ENROLLED;
                $note = 'CMS shows status "' . $record->member_status
                    . '"; local record is still enrolled.';
            }

            // Retroactive adjustment check — overrides only if no status mismatch flagged above.
            if ($disc === null && abs((float) $record->adjustment_amount) > 0.01) {
                $disc = MmrRecord::DISC_RETROACTIVE_ADJUSTMENT;
                $note = 'Retroactive adjustment of $' . number_format((float) $record->adjustment_amount, 2)
                    . ' for prior-period correction.';
            }

            // Capitation variance — only if the member is CMS-active and no more
            // severe discrepancy already found.
            if ($disc === null && $cmsEnrolled) {
                $expected = $expectedCapByParticipant[$matched->id] ?? null;
                if ($expected !== null) {
                    $delta = (float) $record->capitation_amount - $expected;
                    if (abs($delta) > self::CAPITATION_VARIANCE_THRESHOLD) {
                        $disc = MmrRecord::DISC_CAPITATION_VARIANCE;
                        $note = sprintf(
                            'Capitation variance: CMS paid $%s, expected $%s (delta $%s).',
                            number_format((float) $record->capitation_amount, 2),
                            number_format($expected, 2),
                            number_format($delta, 2)
                        );
                    }
                }
            }

            $record->update([
                'matched_participant_id' => $matched->id,
                'discrepancy_type'       => $disc,
                'discrepancy_note'       => $note,
            ]);

            if ($disc !== null) $discrepancyCount++;
        }

        return $discrepancyCount;
    }

    /**
     * Summary of a parsed MMR file for finance review. Includes the "local
     * enrolled but missing on MMR" count which is a file-level gap, not a
     * per-record flag.
     *
     * @return array<string, int|float|string>
     */
    public function reconciliationSummary(MmrFile $file): array
    {
        $records = MmrRecord::where('mmr_file_id', $file->id)->get();

        $mbiSetFromMmr = $records->pluck('medicare_id')->filter()->unique();

        $locallyEnrolledMissingFromMmr = Participant::where('tenant_id', $file->tenant_id)
            ->where('enrollment_status', 'enrolled')
            ->get(['id', 'medicare_id'])
            ->filter(fn ($p) => $p->medicare_id && ! $mbiSetFromMmr->contains($p->medicare_id))
            ->count();

        return [
            'record_count'                        => $records->count(),
            'total_capitation'                    => (float) $records->sum('capitation_amount'),
            'total_adjustment'                    => (float) $records->sum('adjustment_amount'),
            'discrepancy_cms_enrolled_not_local'  => $records->where('discrepancy_type', MmrRecord::DISC_CMS_ENROLLED_NOT_LOCAL)->count(),
            'discrepancy_cms_disenrolled_local'   => $records->where('discrepancy_type', MmrRecord::DISC_CMS_DISENROLLED_LOCAL_ENROLLED)->count(),
            'discrepancy_capitation_variance'     => $records->where('discrepancy_type', MmrRecord::DISC_CAPITATION_VARIANCE)->count(),
            'discrepancy_retroactive_adjustment'  => $records->where('discrepancy_type', MmrRecord::DISC_RETROACTIVE_ADJUSTMENT)->count(),
            'discrepancy_unmatched_mbi'           => $records->where('discrepancy_type', MmrRecord::DISC_UNMATCHED_MBI)->count(),
            'locally_enrolled_missing_from_mmr'   => $locallyEnrolledMissingFromMmr,
        ];
    }
}
