<?php

// ─── RemittanceClaim ──────────────────────────────────────────────────────────
//
// Represents a single claim adjudication record (CLP segment) from an X12 835
// ERA batch. Each row captures how the payer adjudicated one specific claim
// that was previously submitted on an 837P transaction.
//
// Append-only model (UPDATED_AT = null) : 835 adjudication data is immutable
// once parsed. Post-adjudication workflow is tracked in emr_denial_records.
//
// Claim status codes map to X12 CLP02 standard values:
//   paid_full  → 1  | paid_partial → 2  | denied → 3
//   reversed   → 4  | forwarded    → 13 | pending → 19 | other → any other

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class RemittanceClaim extends Model
{
    use HasFactory;

    protected $table = 'emr_remittance_claims';

    /** Disable updated_at : 835 claim data is append-only. */
    public const UPDATED_AT = null;

    // ── Claim status constants ─────────────────────────────────────────────────

    public const STATUSES = [
        'paid_full',
        'paid_partial',
        'denied',
        'reversed',
        'forwarded',
        'pending',
        'other',
    ];

    public const STATUS_LABELS = [
        'paid_full'    => 'Paid in Full',
        'paid_partial' => 'Partial Payment',
        'denied'       => 'Denied',
        'reversed'     => 'Reversed',
        'forwarded'    => 'Forwarded',
        'pending'      => 'Pending',
        'other'        => 'Other',
    ];

    /** X12 CLP02 integer codes → our status strings. Used during 835 parsing. */
    public const CLP_STATUS_MAP = [
        '1'  => 'paid_full',
        '2'  => 'paid_partial',
        '3'  => 'denied',
        '4'  => 'reversed',
        '13' => 'forwarded',
        '19' => 'pending',
        '22' => 'reversed',
    ];

    // ── Fillable ───────────────────────────────────────────────────────────────

    protected $fillable = [
        'remittance_batch_id',
        'tenant_id',
        'edi_batch_id',
        'encounter_log_id',
        'patient_control_number',
        'claim_status',
        'submitted_amount',
        'allowed_amount',
        'paid_amount',
        'patient_responsibility',
        'payer_claim_number',
        'service_date_from',
        'service_date_to',
        'rendering_provider_npi',
        'remittance_date',
    ];

    protected $casts = [
        'service_date_from'      => 'date',
        'service_date_to'        => 'date',
        'remittance_date'        => 'date',
        'submitted_amount'       => 'decimal:2',
        'allowed_amount'         => 'decimal:2',
        'paid_amount'            => 'decimal:2',
        'patient_responsibility' => 'decimal:2',
    ];

    // ── Relationships ──────────────────────────────────────────────────────────

    public function batch(): BelongsTo
    {
        return $this->belongsTo(RemittanceBatch::class, 'remittance_batch_id');
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }

    public function ediBatch(): BelongsTo
    {
        return $this->belongsTo(EdiBatch::class, 'edi_batch_id');
    }

    public function encounterLog(): BelongsTo
    {
        return $this->belongsTo(EncounterLog::class, 'encounter_log_id');
    }

    /** CAS segment adjustments : the "why" behind the payment difference. */
    public function adjustments(): HasMany
    {
        return $this->hasMany(RemittanceAdjustment::class, 'remittance_claim_id');
    }

    /** Denial record if this claim was denied and entered the denial workflow. */
    public function denialRecord(): HasOne
    {
        return $this->hasOne(DenialRecord::class, 'remittance_claim_id');
    }

    // ── Query Scopes ───────────────────────────────────────────────────────────

    public function scopeForTenant($query, int $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }

    public function scopeForBatch($query, int $batchId)
    {
        return $query->where('remittance_batch_id', $batchId);
    }

    public function scopeDenied($query)
    {
        return $query->where('claim_status', 'denied');
    }

    public function scopePaidFull($query)
    {
        return $query->where('claim_status', 'paid_full');
    }

    public function scopePartial($query)
    {
        return $query->where('claim_status', 'paid_partial');
    }

    // ── Business logic ─────────────────────────────────────────────────────────

    public function isDenied(): bool
    {
        return $this->claim_status === 'denied';
    }

    public function isPaid(): bool
    {
        return in_array($this->claim_status, ['paid_full', 'paid_partial'], true);
    }

    /** Amount the payer reduced from the submitted charge. */
    public function adjustmentTotal(): float
    {
        return (float) ($this->submitted_amount - $this->allowed_amount);
    }

    /** Amount difference between allowed and paid (additional reductions). */
    public function contractualWriteOff(): float
    {
        return (float) ($this->allowed_amount - $this->paid_amount - $this->patient_responsibility);
    }

    public function statusLabel(): string
    {
        return self::STATUS_LABELS[$this->claim_status] ?? ucfirst($this->claim_status);
    }

    /**
     * Map an X12 CLP02 integer status code to our claim_status string.
     * Returns 'other' for any unrecognized CLP02 value.
     */
    public static function mapClpStatus(string $clpCode): string
    {
        return self::CLP_STATUS_MAP[$clpCode] ?? 'other';
    }

    // ── API Serialization ──────────────────────────────────────────────────────

    public function toApiArray(): array
    {
        return [
            'id'                     => $this->id,
            'remittance_batch_id'    => $this->remittance_batch_id,
            'patient_control_number' => $this->patient_control_number,
            'claim_status'           => $this->claim_status,
            'claim_status_label'     => $this->statusLabel(),
            'submitted_amount'       => (float) $this->submitted_amount,
            'allowed_amount'         => (float) $this->allowed_amount,
            'paid_amount'            => (float) $this->paid_amount,
            'patient_responsibility' => (float) $this->patient_responsibility,
            'payer_claim_number'     => $this->payer_claim_number,
            'service_date_from'      => $this->service_date_from?->toDateString(),
            'service_date_to'        => $this->service_date_to?->toDateString(),
            'remittance_date'        => $this->remittance_date?->toDateString(),
            'encounter_log_id'       => $this->encounter_log_id,
            'created_at'             => $this->created_at?->toIso8601String(),
        ];
    }
}
