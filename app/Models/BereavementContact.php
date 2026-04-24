<?php

// ─── BereavementContact ──────────────────────────────────────────────────────
// Phase C3. Family bereavement check-ins scheduled after a participant's death.
// Standard cadence: 15 days, 30 days, 3 months.
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BereavementContact extends Model
{
    protected $table = 'emr_bereavement_contacts';

    public const TYPES    = ['day_15', 'day_30', 'month_3'];
    public const STATUSES = ['scheduled', 'completed', 'missed', 'declined'];

    /** Standard cadence applied on death: (type, days-after-death) pairs. */
    public const CADENCE = [
        ['type' => 'day_15',  'days' => 15],
        ['type' => 'day_30',  'days' => 30],
        ['type' => 'month_3', 'days' => 90],
    ];

    protected $fillable = [
        'tenant_id', 'participant_id', 'contact_type',
        'family_contact_name', 'family_contact_phone',
        'scheduled_at', 'status', 'completed_at', 'completed_by_user_id', 'notes',
    ];

    protected $casts = [
        'scheduled_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function tenant(): BelongsTo      { return $this->belongsTo(Tenant::class); }
    public function participant(): BelongsTo { return $this->belongsTo(Participant::class); }
    public function completedBy(): BelongsTo { return $this->belongsTo(User::class, 'completed_by_user_id'); }

    public function scopeForTenant($q, int $t) { return $q->where('tenant_id', $t); }
    public function scopeScheduled($q)         { return $q->where('status', 'scheduled'); }
}
