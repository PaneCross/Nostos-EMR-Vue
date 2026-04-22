<?php

// ─── ClearinghouseTransmission ───────────────────────────────────────────────
// Phase 12. Append-only wire-level audit of every outbound claim transmission
// and every inbound acknowledgment/remittance. Exists even under the Null
// gateway (entries carry status='staged_manual') so the operator always has
// a record of what happened.
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClearinghouseTransmission extends Model
{
    protected $table = 'emr_clearinghouse_transmissions';

    public const DIRECTIONS = ['outbound', 'inbound'];
    public const STATUSES = ['staged_manual', 'pending', 'submitted', 'accepted', 'rejected', 'timeout', 'error'];

    protected $fillable = [
        'tenant_id', 'edi_batch_id', 'config_id', 'adapter',
        'direction', 'transaction_kind', 'vendor_transaction_id',
        'status', 'attempted_at', 'completed_at', 'attempt_number',
        'raw_payload', 'error_message',
    ];

    protected $casts = [
        'attempted_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function batch(): BelongsTo
    {
        return $this->belongsTo(EdiBatch::class, 'edi_batch_id');
    }

    public function config(): BelongsTo
    {
        return $this->belongsTo(ClearinghouseConfig::class, 'config_id');
    }

    public function scopeForTenant($q, int $tenantId)
    {
        return $q->where('tenant_id', $tenantId);
    }
}
