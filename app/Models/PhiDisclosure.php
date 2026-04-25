<?php

// ─── PhiDisclosure — Phase P2 ───────────────────────────────────────────────
// HIPAA §164.528 Accounting of Disclosures. Append-only — never updated;
// records the moment a PHI release happened.
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class PhiDisclosure extends Model
{
    protected $table = 'emr_phi_disclosures';

    public const RECIPIENT_TYPES = [
        'insurer', 'public_health', 'lab', 'family', 'legal',
        'patient_self', 'provider', 'other',
    ];

    public const METHODS = ['paper', 'fax', 'email', 'portal', 'api', 'hie'];

    protected $fillable = [
        'tenant_id', 'participant_id',
        'disclosed_at', 'disclosed_by_user_id',
        'recipient_type', 'recipient_name', 'recipient_contact',
        'disclosure_purpose', 'disclosure_method',
        'records_described',
        'related_to_type', 'related_to_id',
    ];

    protected $casts = [
        'disclosed_at' => 'datetime',
    ];

    public function participant(): BelongsTo { return $this->belongsTo(Participant::class); }
    public function disclosedBy(): BelongsTo  { return $this->belongsTo(User::class, 'disclosed_by_user_id'); }
    public function relatedTo(): MorphTo      { return $this->morphTo(null, 'related_to_type', 'related_to_id'); }

    public function scopeForTenant($q, int $t)        { return $q->where('tenant_id', $t); }
    public function scopeForParticipant($q, int $pid) { return $q->where('participant_id', $pid); }

    /** §164.528(a)(1) — accounting period is 6 years prior to the request. */
    public function scopeAccountingPeriod($q)
    {
        return $q->where('disclosed_at', '>=', now()->subYears(6));
    }
}
