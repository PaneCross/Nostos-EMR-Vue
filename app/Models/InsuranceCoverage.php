<?php

// ─── InsuranceCoverage ────────────────────────────────────────────────────────
// One payer coverage record for a participant. PACE participants are nearly
// always dually-eligible (Medicare + Medicaid), so it is normal to see
// multiple active rows per participant: Medicare Part A, Part B, Part D
// (drugs), and state Medicaid.
//
// Drives claim submission (X12 837P), pharmacy routing (BIN/PCN), and the
// Medicaid spend-down workflow (the participant's monthly share-of-cost
// obligation that must be met before Medicaid pays).
//
// Notable rules:
//  - 45 CFR §164.312(a)(2)(iv) HIPAA: `member_id` and `bin_pcn` are PHI and
//    are encrypted at rest via Laravel's `encrypted` cast.
//  - Tenant-scoped through the parent Participant.
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InsuranceCoverage extends Model
{
    protected $table = 'emr_insurance_coverages';

    protected $fillable = [
        'participant_id', 'payer_type',
        'member_id', 'group_id', 'plan_name', 'bin_pcn',
        'effective_date', 'term_date', 'is_active',
        // Phase 7 (MVP roadmap): Medicaid spend-down / share-of-cost
        'share_of_cost_monthly_amount',
        'spend_down_threshold',
        'spend_down_period_start',
        'spend_down_period_end',
        'spend_down_state',
    ];

    protected $casts = [
        'effective_date'                => 'date',
        'term_date'                     => 'date',
        'is_active'                     => 'boolean',
        // W4-2 HIPAA §164.312(a)(2)(iv): payer identifier fields encrypted at rest.
        'member_id'                     => 'encrypted',
        'bin_pcn'                       => 'encrypted',
        'share_of_cost_monthly_amount'  => 'decimal:2',
        'spend_down_threshold'          => 'decimal:2',
        'spend_down_period_start'       => 'date',
        'spend_down_period_end'         => 'date',
    ];

    public function participant(): BelongsTo
    {
        return $this->belongsTo(Participant::class, 'participant_id');
    }

    public function payerLabel(): string
    {
        return match ($this->payer_type) {
            'medicare_a' => 'Medicare Part A',
            'medicare_b' => 'Medicare Part B',
            'medicare_d' => 'Medicare Part D',
            'medicaid'   => 'Medicaid',
            default      => 'Other',
        };
    }

    /**
     * Phase 7 (MVP roadmap): true when this coverage carries a spend-down
     * obligation. Either a monthly share-of-cost amount or a period threshold
     * triggers the reconciliation workflow.
     */
    public function hasSpendDown(): bool
    {
        return $this->payer_type === 'medicaid'
            && $this->is_active
            && (
                ((float) $this->share_of_cost_monthly_amount > 0.0)
                || ((float) $this->spend_down_threshold > 0.0)
            );
    }
}
