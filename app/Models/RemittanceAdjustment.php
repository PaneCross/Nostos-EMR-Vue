<?php

// ─── RemittanceAdjustment ─────────────────────────────────────────────────────
//
// Stores one CAS (Claim Adjustment Segment) line from an X12 835 ERA file.
// Each RemittanceClaim can have multiple adjustments explaining the difference
// between the submitted amount and what the payer actually paid.
//
// X12 adjustment group codes:
//   CO : Contractual Obligation: payer contract write-off (non-actionable)
//   OA : Other Adjustment: various payer-initiated reductions
//   PI : Payer Initiated Reductions: payer's own error or policy
//   PR : Patient Responsibility: copay, deductible, or coinsurance owed by patient
//
// Append-only (UPDATED_AT = null) : CAS data is immutable once parsed from 835.

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RemittanceAdjustment extends Model
{
    use HasFactory;

    protected $table = 'emr_remittance_adjustments';

    /** Disable updated_at : 835 CAS data is append-only. */
    public const UPDATED_AT = null;

    // ── Adjustment group code constants ───────────────────────────────────────

    /** X12 standard adjustment group codes. */
    public const GROUP_CODES = ['CO', 'OA', 'PI', 'PR'];

    public const GROUP_CODE_LABELS = [
        'CO' => 'Contractual Obligation',
        'OA' => 'Other Adjustment',
        'PI' => 'Payer Initiated Reduction',
        'PR' => 'Patient Responsibility',
    ];

    // ── Fillable ───────────────────────────────────────────────────────────────

    protected $fillable = [
        'remittance_claim_id',
        'tenant_id',
        'adjustment_group_code',
        'reason_code',
        'adjustment_amount',
        'adjustment_quantity',
        'service_line_id',
    ];

    protected $casts = [
        'adjustment_amount'   => 'decimal:2',
        'adjustment_quantity' => 'decimal:2',
    ];

    // ── Relationships ──────────────────────────────────────────────────────────

    public function claim(): BelongsTo
    {
        return $this->belongsTo(RemittanceClaim::class, 'remittance_claim_id');
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }

    /** Look up the CARC description for this adjustment's reason code. */
    public function carcCode(): BelongsTo
    {
        return $this->belongsTo(CarcCode::class, 'reason_code', 'code');
    }

    // ── Query Scopes ───────────────────────────────────────────────────────────

    public function scopeForTenant($query, int $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }

    public function scopeForClaim($query, int $claimId)
    {
        return $query->where('remittance_claim_id', $claimId);
    }

    /** Contractual write-offs : expected, non-actionable reductions. */
    public function scopeContractual($query)
    {
        return $query->where('adjustment_group_code', 'CO');
    }

    /** Patient responsibility amounts : copay/deductible/coinsurance. */
    public function scopePatientResponsibility($query)
    {
        return $query->where('adjustment_group_code', 'PR');
    }

    // ── Business logic ─────────────────────────────────────────────────────────

    public function isContractual(): bool
    {
        return $this->adjustment_group_code === 'CO';
    }

    public function isPatientResponsibility(): bool
    {
        return $this->adjustment_group_code === 'PR';
    }

    public function isPayerInitiated(): bool
    {
        return $this->adjustment_group_code === 'PI';
    }

    public function groupCodeLabel(): string
    {
        return self::GROUP_CODE_LABELS[$this->adjustment_group_code] ?? $this->adjustment_group_code;
    }

    // ── API Serialization ──────────────────────────────────────────────────────

    public function toApiArray(): array
    {
        return [
            'id'                    => $this->id,
            'remittance_claim_id'   => $this->remittance_claim_id,
            'adjustment_group_code' => $this->adjustment_group_code,
            'group_code_label'      => $this->groupCodeLabel(),
            'reason_code'           => $this->reason_code,
            'adjustment_amount'     => (float) $this->adjustment_amount,
            'adjustment_quantity'   => $this->adjustment_quantity ? (float) $this->adjustment_quantity : null,
            'service_line_id'       => $this->service_line_id,
            'created_at'            => $this->created_at?->toIso8601String(),
        ];
    }
}
