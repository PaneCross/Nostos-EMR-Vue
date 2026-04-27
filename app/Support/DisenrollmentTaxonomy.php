<?php

// ─── DisenrollmentTaxonomy ────────────────────────────────────────────────────
// Canonical PACE disenrollment reasons + derived type per 42 CFR §460.160-164
// and CMS PACE Manual Ch. 4.
//
// Reasons split into three types:
//   death       : §460.160(b): terminates enrollment on actual date of death.
//   voluntary   : §460.162: participant-initiated, no cause required.
//   involuntary : §460.164(b): PACE-initiated from an enumerated list.
//
// See memory: feedback_pace_disenrollment_taxonomy.md
// ──────────────────────────────────────────────────────────────────────────────

namespace App\Support;

class DisenrollmentTaxonomy
{
    public const TYPE_DEATH       = 'death';
    public const TYPE_VOLUNTARY   = 'voluntary';
    public const TYPE_INVOLUNTARY = 'involuntary';

    public const TYPES = [
        self::TYPE_DEATH,
        self::TYPE_VOLUNTARY,
        self::TYPE_INVOLUNTARY,
    ];

    /**
     * Reason → type map. The key is the canonical reason string stored in
     * participants.disenrollment_reason. The value is the rollup type.
     */
    public const REASON_TYPE_MAP = [
        // ── Death (§460.160(b)) ─────────────────────────────────────────────
        'death' => self::TYPE_DEATH,

        // ── Voluntary (§460.162) ───────────────────────────────────────────
        'voluntary_moved_out_of_area'            => self::TYPE_VOLUNTARY,
        'voluntary_dissatisfied'                 => self::TYPE_VOLUNTARY,
        'voluntary_elected_hospice_outside_pace' => self::TYPE_VOLUNTARY,
        'voluntary_other'                        => self::TYPE_VOLUNTARY,

        // ── Involuntary (§460.164(b) : enumerated list) ────────────────────
        'involuntary_nonpayment_premium'            => self::TYPE_INVOLUNTARY,
        'involuntary_nonpayment_medicaid_liability' => self::TYPE_INVOLUNTARY,
        'involuntary_disruptive_participant'        => self::TYPE_INVOLUNTARY,
        'involuntary_disruptive_caregiver'          => self::TYPE_INVOLUNTARY,
        'involuntary_out_of_service_area'           => self::TYPE_INVOLUNTARY,
        'involuntary_loss_of_nf_loc_eligibility'    => self::TYPE_INVOLUNTARY,
        'involuntary_program_termination'           => self::TYPE_INVOLUNTARY,
        'involuntary_loss_of_licensure'             => self::TYPE_INVOLUNTARY,
    ];

    /** All canonical reason strings. */
    public const REASONS = [
        'death',
        'voluntary_moved_out_of_area',
        'voluntary_dissatisfied',
        'voluntary_elected_hospice_outside_pace',
        'voluntary_other',
        'involuntary_nonpayment_premium',
        'involuntary_nonpayment_medicaid_liability',
        'involuntary_disruptive_participant',
        'involuntary_disruptive_caregiver',
        'involuntary_out_of_service_area',
        'involuntary_loss_of_nf_loc_eligibility',
        'involuntary_program_termination',
        'involuntary_loss_of_licensure',
    ];

    /** Derive the disenrollment_type from a reason string. Returns null if unknown. */
    public static function typeForReason(?string $reason): ?string
    {
        return $reason ? (self::REASON_TYPE_MAP[$reason] ?? null) : null;
    }

    /**
     * Display-friendly labels for reasons. Used in <select> options and reports.
     */
    public static function labels(): array
    {
        return [
            'death'                                     => 'Death',
            'voluntary_moved_out_of_area'               => 'Moved out of service area (voluntary)',
            'voluntary_dissatisfied'                    => 'Dissatisfied with services',
            'voluntary_elected_hospice_outside_pace'    => 'Elected hospice outside PACE',
            'voluntary_other'                           => 'Other (voluntary)',
            'involuntary_nonpayment_premium'            => 'Non-payment of premium',
            'involuntary_nonpayment_medicaid_liability' => 'Non-payment of Medicaid liability / spend-down',
            'involuntary_disruptive_participant'        => 'Disruptive/threatening behavior (participant)',
            'involuntary_disruptive_caregiver'          => 'Disruptive/threatening behavior (caregiver)',
            'involuntary_out_of_service_area'           => 'Moved permanently out of service area',
            'involuntary_loss_of_nf_loc_eligibility'    => 'No longer meets NF level-of-care eligibility',
            'involuntary_program_termination'           => 'PACE program agreement terminated',
            'involuntary_loss_of_licensure'             => 'PACE org lost required licensure',
        ];
    }

    /** Labels grouped by type, for optgroup-style selects. */
    public static function groupedLabels(): array
    {
        $labels = self::labels();
        $grouped = [self::TYPE_DEATH => [], self::TYPE_VOLUNTARY => [], self::TYPE_INVOLUNTARY => []];
        foreach (self::REASON_TYPE_MAP as $reason => $type) {
            $grouped[$type][$reason] = $labels[$reason] ?? $reason;
        }
        return $grouped;
    }
}
