<?php

// ─── RemittanceBatch ──────────────────────────────────────────────────────────
//
// Represents an inbound X12 835 Electronic Remittance Advice (ERA) batch.
// Each batch is one payment from a payer (CMS, Medicaid MCO, commercial) that
// covers adjudication for one or more previously submitted claims.
//
// The raw EDI 835 content is stored for audit trail per HIPAA 45 CFR §164.312(b).
//
// Status lifecycle:
//   received → processing → processed → posted | error
//
// Claim counts (claim_count, paid_count, denied_count, adjustment_count) are
// updated by Process835RemittanceJob after parsing is complete.
//
// Routes (handled by RemittanceController):
//   POST /finance/remittance/upload       → upload + dispatch job
//   GET  /finance/remittance              → index (list batches)
//   GET  /finance/remittance/{batch}      → show (batch detail)
//   GET  /finance/remittance/{batch}/claims → claims for batch

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class RemittanceBatch extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'emr_remittance_batches';

    // ── Status lifecycle ──────────────────────────────────────────────────────

    /** Status values per the status lifecycle comment above. */
    public const STATUSES = ['received', 'processing', 'processed', 'posted', 'error'];

    public const STATUS_LABELS = [
        'received'   => 'Received',
        'processing' => 'Processing',
        'processed'  => 'Processed',
        'posted'     => 'Posted',
        'error'      => 'Error',
    ];

    // ── Payment method codes ──────────────────────────────────────────────────

    /** Payment method as parsed from X12 BPR04 segment. */
    public const PAYMENT_METHODS = ['check', 'eft', 'virtual_card', 'other'];

    public const PAYMENT_METHOD_LABELS = [
        'check'        => 'Check',
        'eft'          => 'EFT / ACH',
        'virtual_card' => 'Virtual Card',
        'other'        => 'Other',
    ];

    // ── Source ────────────────────────────────────────────────────────────────

    /** How the 835 file arrived in the system. */
    public const SOURCES = ['manual_upload', 'clearinghouse_auto'];

    // ── Fillable ──────────────────────────────────────────────────────────────

    protected $fillable = [
        'tenant_id',
        'file_name',
        'check_eft_number',
        'payer_name',
        'payer_id',
        'edi_835_content',
        'payment_date',
        'payment_amount',
        'check_issue_date',
        'payment_method',
        'status',
        'source',
        'claim_count',
        'paid_count',
        'denied_count',
        'adjustment_count',
        'processed_at',
        'created_by_user_id',
    ];

    protected $casts = [
        'payment_date'      => 'date',
        'check_issue_date'  => 'date',
        'processed_at'      => 'datetime',
        'payment_amount'    => 'decimal:2',
        'claim_count'       => 'integer',
        'paid_count'        => 'integer',
        'denied_count'      => 'integer',
        'adjustment_count'  => 'integer',
    ];

    // ── Relationships ─────────────────────────────────────────────────────────

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /** All claim-level adjudication records parsed from this batch. */
    public function claims(): HasMany
    {
        return $this->hasMany(RemittanceClaim::class, 'remittance_batch_id');
    }

    /** All denial records auto-created from denied claims in this batch. */
    public function denials(): HasMany
    {
        return $this->hasMany(DenialRecord::class, 'remittance_batch_id')
            ->join('emr_remittance_claims', 'emr_denial_records.remittance_claim_id', '=', 'emr_remittance_claims.id')
            ->where('emr_remittance_claims.remittance_batch_id', $this->id);
    }

    // ── Query Scopes ──────────────────────────────────────────────────────────

    /** Scope to a specific tenant — always apply in multi-tenant queries. */
    public function scopeForTenant($query, int $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }

    public function scopeWithStatus($query, string $status)
    {
        return $query->where('status', $status);
    }

    /** Batches that have not yet completed processing. */
    public function scopePending($query)
    {
        return $query->whereIn('status', ['received', 'processing']);
    }

    /** Batches that contain at least one denied claim. */
    public function scopeWithDenials($query)
    {
        return $query->where('denied_count', '>', 0);
    }

    // ── Business logic ────────────────────────────────────────────────────────

    /** Whether this batch is in a terminal (unchangeable) state. */
    public function isTerminal(): bool
    {
        return in_array($this->status, ['posted', 'error'], true);
    }

    public function isProcessed(): bool
    {
        return $this->status === 'processed';
    }

    public function isPosted(): bool
    {
        return $this->status === 'posted';
    }

    public function hasErrors(): bool
    {
        return $this->status === 'error';
    }

    /** Overall denial rate as a percentage of total claims. */
    public function denialRate(): float
    {
        if ($this->claim_count === 0) {
            return 0.0;
        }

        return round(($this->denied_count / $this->claim_count) * 100, 1);
    }

    /** Human-readable payment method label. */
    public function paymentMethodLabel(): string
    {
        return self::PAYMENT_METHOD_LABELS[$this->payment_method] ?? ucfirst($this->payment_method ?? 'Unknown');
    }

    public function statusLabel(): string
    {
        return self::STATUS_LABELS[$this->status] ?? ucfirst($this->status);
    }

    // ── API Serialization ─────────────────────────────────────────────────────

    /**
     * Compact array for list views.
     * Raw EDI content is excluded — never expose bulk EDI in API list responses.
     */
    public function toApiArray(): array
    {
        return [
            'id'               => $this->id,
            'file_name'        => $this->file_name,
            'check_eft_number' => $this->check_eft_number,
            'payer_name'       => $this->payer_name,
            'payer_id'         => $this->payer_id,
            'payment_date'     => $this->payment_date?->toDateString(),
            'payment_amount'   => (float) $this->payment_amount,
            'payment_method'   => $this->payment_method,
            'payment_method_label' => $this->paymentMethodLabel(),
            'status'           => $this->status,
            'status_label'     => $this->statusLabel(),
            'source'           => $this->source,
            'claim_count'      => $this->claim_count,
            'paid_count'       => $this->paid_count,
            'denied_count'     => $this->denied_count,
            'adjustment_count' => $this->adjustment_count,
            'denial_rate'      => $this->denialRate(),
            'processed_at'     => $this->processed_at?->toIso8601String(),
            'created_at'       => $this->created_at?->toIso8601String(),
        ];
    }
}
