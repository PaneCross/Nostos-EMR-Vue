<?php

// ─── TbScreening ─────────────────────────────────────────────────────────────
// Phase C2a. §460.71 : annual TB screening documentation.
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TbScreening extends Model
{
    use HasFactory;

    protected $table = 'emr_tb_screenings';

    public const TYPES   = ['ppd', 'quantiferon', 't_spot', 'chest_xray', 'symptom_only'];
    public const RESULTS = ['positive', 'negative', 'indeterminate'];

    /** §460.71 annual cadence. */
    public const RECERT_DAYS = 365;

    protected $fillable = [
        'tenant_id', 'participant_id', 'recorded_by_user_id',
        'screening_type', 'performed_date', 'result', 'induration_mm',
        'follow_up_text', 'next_due_date', 'notes',
    ];

    protected $casts = [
        'performed_date' => 'date',
        'next_due_date'  => 'date',
        'induration_mm'  => 'decimal:1',
    ];

    public function participant(): BelongsTo { return $this->belongsTo(Participant::class); }
    public function tenant(): BelongsTo      { return $this->belongsTo(Tenant::class); }
    public function recordedBy(): BelongsTo  { return $this->belongsTo(User::class, 'recorded_by_user_id'); }

    public function scopeForTenant($q, int $t) { return $q->where('tenant_id', $t); }

    public function daysUntilDue(): ?int
    {
        return $this->next_due_date
            ? (int) round(now()->startOfDay()->diffInDays($this->next_due_date, false))
            : null;
    }
}
