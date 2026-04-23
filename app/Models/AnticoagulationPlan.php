<?php

// ─── AnticoagulationPlan ─────────────────────────────────────────────────────
// Phase B5. One active plan per participant × agent. target_inr_low/high
// apply only for warfarin plans; DOAC plans leave them null (DOACs don't
// require INR monitoring).
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AnticoagulationPlan extends Model
{
    protected $table = 'emr_anticoagulation_plans';

    public const AGENTS = ['warfarin', 'apixaban', 'rivaroxaban', 'dabigatran', 'edoxaban', 'enoxaparin', 'other'];
    public const INR_MONITORED_AGENTS = ['warfarin'];

    /** Default monitoring interval for warfarin when unspecified. */
    public const DEFAULT_WARFARIN_MONITOR_DAYS = 30;

    protected $fillable = [
        'tenant_id', 'participant_id', 'agent',
        'target_inr_low', 'target_inr_high', 'monitoring_interval_days',
        'start_date', 'stop_date', 'stop_reason',
        'prescribing_provider_user_id', 'notes',
    ];

    protected $casts = [
        'target_inr_low'  => 'decimal:1',
        'target_inr_high' => 'decimal:1',
        'start_date'      => 'date',
        'stop_date'       => 'date',
    ];

    public function tenant(): BelongsTo            { return $this->belongsTo(Tenant::class); }
    public function participant(): BelongsTo       { return $this->belongsTo(Participant::class); }
    public function prescribingProvider(): BelongsTo { return $this->belongsTo(User::class, 'prescribing_provider_user_id'); }
    public function inrResults(): HasMany          { return $this->hasMany(InrResult::class, 'anticoagulation_plan_id')->orderByDesc('drawn_at'); }

    public function scopeForTenant($q, int $tenantId) { return $q->where('tenant_id', $tenantId); }
    public function scopeActive($q)                   { return $q->whereNull('stop_date'); }

    public function isActive(): bool       { return $this->stop_date === null; }
    public function requiresInr(): bool    { return in_array($this->agent, self::INR_MONITORED_AGENTS, true); }

    /**
     * Evaluate a numeric INR value against this plan's target range. Returns:
     *   'in_range' | 'low' | 'high' | 'critical_low' | 'critical_high' | 'no_target'
     *
     * Critical thresholds: >= 0.5 outside the range OR value >= 5.0 (bleed risk).
     */
    public function evaluateInr(float $value): string
    {
        if ($this->target_inr_low === null || $this->target_inr_high === null) return 'no_target';
        $low = (float) $this->target_inr_low;
        $high = (float) $this->target_inr_high;
        if ($value >= 5.0)           return 'critical_high';
        if ($value < $low - 0.5)     return 'critical_low';
        if ($value > $high + 0.5)    return 'critical_high';
        if ($value < $low)           return 'low';
        if ($value > $high)          return 'high';
        return 'in_range';
    }
}
