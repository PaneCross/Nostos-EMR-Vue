<?php

// ─── ImmunizationSubmission ──────────────────────────────────────────────────
// Phase 8 (MVP roadmap). Append-only record of HL7 VXU messages generated for
// an immunization. Used to audit what was sent, when, and how the registry
// responded (when/if real transmission is wired). Today, submission status is
// a tracking flag : UI labels explicitly say "simulated" until a state
// integration is live.
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ImmunizationSubmission extends Model
{
    protected $table = 'emr_immunization_submissions';

    public const STATUSES = ['generated', 'submitted', 'acknowledged', 'rejected'];

    protected $fillable = [
        'tenant_id', 'participant_id', 'immunization_id', 'state_code',
        'message_control_id', 'vxu_message', 'status',
        'submitted_at', 'acknowledged_at', 'ack_message', 'generated_by_user_id',
    ];

    protected $casts = [
        'submitted_at'    => 'datetime',
        'acknowledged_at' => 'datetime',
    ];

    public function participant(): BelongsTo
    {
        return $this->belongsTo(Participant::class);
    }

    public function immunization(): BelongsTo
    {
        return $this->belongsTo(Immunization::class);
    }

    public function generatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generated_by_user_id');
    }

    public function scopeForTenant($q, int $tenantId)
    {
        return $q->where('tenant_id', $tenantId);
    }
}
