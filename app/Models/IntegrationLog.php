<?php

// ─── IntegrationLog ───────────────────────────────────────────────────────────
// Append-only log of every external integration message exchanged with this
// EMR — used for audit, troubleshooting, and retry of failed deliveries.
//
// Connectors that write here:
//  - hl7_adt        : HL7 (Health Level 7) ADT (Admission/Discharge/Transfer)
//                     messages from hospital partners — tells us when our
//                     participant is admitted/discharged elsewhere.
//  - lab_results    : Inbound HL7 ORU lab results.
//  - pharmacy_ncpdp : Pharmacy network messaging (NCPDP standard).
// Lifecycle: pending → processed | failed; failed entries can be retried by
// IT Admin (status flips to `retried`, retry_count increments).
//
// Notable rules:
//  - Append-only: no `updated_at`, no soft-deletes (HIPAA non-repudiation).
//  - Tenant-scoped: every query must filter by tenant_id.
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IntegrationLog extends Model
{
    use HasFactory;

    protected $table = 'emr_integration_log';

    // Append-only — no updated_at column
    public $timestamps = false;

    // ── Constants ─────────────────────────────────────────────────────────────

    public const CONNECTOR_TYPES = ['hl7_adt', 'lab_results', 'pharmacy_ncpdp', 'other'];
    public const DIRECTIONS       = ['inbound', 'outbound'];
    public const STATUSES         = ['pending', 'processed', 'failed', 'retried'];

    // ── Fillable / Casts ──────────────────────────────────────────────────────

    protected $fillable = [
        'tenant_id',
        'connector_type',
        'direction',
        'raw_payload',
        'processed_at',
        'status',
        'error_message',
        'retry_count',
    ];

    protected $casts = [
        'raw_payload'  => 'array',
        'processed_at' => 'datetime',
        'retry_count'  => 'integer',
    ];

    // ── Relations ─────────────────────────────────────────────────────────────

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    // ── Methods ───────────────────────────────────────────────────────────────

    /** Mark as successfully processed. */
    public function markProcessed(): bool
    {
        return $this->forceFill([
            'status'       => 'processed',
            'processed_at' => now(),
        ])->save();
    }

    /** Mark as failed with error message. */
    public function markFailed(string $errorMessage): bool
    {
        return $this->forceFill([
            'status'        => 'failed',
            'error_message' => $errorMessage,
            'processed_at'  => now(),
        ])->save();
    }

    /** Increment retry counter and set status to retried. */
    public function markRetried(): bool
    {
        return $this->forceFill([
            'status'      => 'retried',
            'retry_count' => $this->retry_count + 1,
        ])->save();
    }

    // ── Scopes ────────────────────────────────────────────────────────────────

    /** Scope queries to a specific tenant. */
    public function scopeForTenant($query, int $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }

    /** Only failed entries (eligible for retry). */
    public function scopeFailed($query)
    {
        return $query->where('status', 'failed');
    }

    /** Filter by connector type. */
    public function scopeForConnector($query, string $connectorType)
    {
        return $query->where('connector_type', $connectorType);
    }
}
