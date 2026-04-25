<?php

// ─── InrResult ───────────────────────────────────────────────────────────────
// Phase B5. Append-only per-draw INR record.
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InrResult extends Model
{
    use HasFactory;

    protected $table = 'emr_inr_results';

    protected $fillable = [
        'tenant_id', 'participant_id', 'anticoagulation_plan_id',
        'drawn_at', 'value', 'in_range',
        'dose_adjustment_text', 'recorded_by_user_id', 'notes',
    ];

    protected $casts = [
        'drawn_at' => 'datetime',
        'value'    => 'decimal:1',
        'in_range' => 'boolean',
    ];

    public function tenant(): BelongsTo       { return $this->belongsTo(Tenant::class); }
    public function participant(): BelongsTo  { return $this->belongsTo(Participant::class); }
    public function plan(): BelongsTo         { return $this->belongsTo(AnticoagulationPlan::class, 'anticoagulation_plan_id'); }
    public function recordedBy(): BelongsTo   { return $this->belongsTo(User::class, 'recorded_by_user_id'); }

    public function scopeForTenant($q, int $tenantId) { return $q->where('tenant_id', $tenantId); }
}
