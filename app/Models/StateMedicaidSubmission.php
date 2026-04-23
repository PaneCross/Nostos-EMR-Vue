<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StateMedicaidSubmission extends Model
{
    protected $table = 'emr_state_medicaid_submissions';

    public const STATUSES = ['staged_manual', 'pending', 'submitted', 'accepted', 'rejected', 'error'];

    protected $fillable = [
        'tenant_id', 'state_config_id', 'edi_batch_id', 'state_code',
        'submission_format', 'status', 'payload_text',
        'state_transaction_id', 'submitted_at', 'response_notes',
        'prepared_by_user_id',
    ];

    protected $casts = [
        'submitted_at' => 'datetime',
    ];

    public function tenant(): BelongsTo      { return $this->belongsTo(Tenant::class); }
    public function stateConfig(): BelongsTo { return $this->belongsTo(StateMedicaidConfig::class, 'state_config_id'); }
    public function ediBatch(): BelongsTo    { return $this->belongsTo(EdiBatch::class); }

    public function scopeForTenant($q, int $tenantId) { return $q->where('tenant_id', $tenantId); }
}
