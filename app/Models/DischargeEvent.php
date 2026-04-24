<?php

// ─── DischargeEvent ──────────────────────────────────────────────────────────
// Phase C4. Structured hospital discharge checklist for PACE participants.
// checklist is a JSONB array of {key, label, owner_dept, due_days, due_at,
// completed_at, completed_by_user_id, notes} items. DEFAULT_CHECKLIST
// defines the 8 standard items per CMS transitional-care-management protocol.
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DischargeEvent extends Model
{
    protected $table = 'emr_discharge_events';

    /** Standard 8-item discharge checklist. due_days is days post-discharge. */
    public const DEFAULT_CHECKLIST = [
        ['key' => 'med_reconciliation', 'label' => 'Medication reconciliation completed',
            'owner_dept' => 'pharmacy', 'due_days' => 2],
        ['key' => 'discharge_summary', 'label' => 'Discharge summary received + filed in EMR',
            'owner_dept' => 'primary_care', 'due_days' => 1],
        ['key' => 'pcp_followup', 'label' => 'Follow-up PCP appointment scheduled',
            'owner_dept' => 'primary_care', 'due_days' => 7],
        ['key' => 'home_care_referral', 'label' => 'Home care / therapy referrals placed',
            'owner_dept' => 'home_care', 'due_days' => 2],
        ['key' => 'dme_ordered', 'label' => 'DME ordered (if needed)',
            'owner_dept' => 'home_care', 'due_days' => 3],
        ['key' => 'caregiver_notified', 'label' => 'Caregiver / family notified',
            'owner_dept' => 'social_work', 'due_days' => 1],
        ['key' => 'med_delivery', 'label' => 'Medication delivery confirmed',
            'owner_dept' => 'pharmacy', 'due_days' => 2],
        ['key' => 'check_in_scheduled', 'label' => '48h phone check-in scheduled (TCM)',
            'owner_dept' => 'primary_care', 'due_days' => 2],
    ];

    protected $fillable = [
        'tenant_id', 'participant_id', 'discharge_from_facility', 'discharged_on',
        'readmission_risk_score', 'checklist', 'auto_created_from_adt',
        'created_by_user_id', 'notes',
    ];

    protected $casts = [
        'discharged_on'            => 'date',
        'readmission_risk_score'   => 'decimal:2',
        'checklist'                => 'array',
        'auto_created_from_adt'    => 'boolean',
    ];

    public function tenant(): BelongsTo      { return $this->belongsTo(Tenant::class); }
    public function participant(): BelongsTo { return $this->belongsTo(Participant::class); }
    public function createdBy(): BelongsTo   { return $this->belongsTo(User::class, 'created_by_user_id'); }

    public function scopeForTenant($q, int $t) { return $q->where('tenant_id', $t); }

    /**
     * Build a fresh checklist seeded with due_at timestamps from discharged_on.
     */
    public static function buildDefaultChecklist(\Carbon\Carbon $dischargedOn): array
    {
        return array_map(function ($item) use ($dischargedOn) {
            return array_merge($item, [
                'due_at'                => $dischargedOn->copy()->addDays($item['due_days'])->toIso8601String(),
                'completed_at'          => null,
                'completed_by_user_id'  => null,
                'notes'                 => null,
            ]);
        }, self::DEFAULT_CHECKLIST);
    }

    /** @return array<int, array> overdue checklist items (not completed + past due_at) */
    public function overdueItems(): array
    {
        $out = [];
        foreach ($this->checklist ?? [] as $item) {
            if (! empty($item['completed_at'])) continue;
            if (! empty($item['due_at']) && \Carbon\Carbon::parse($item['due_at'])->isPast()) {
                $out[] = $item;
            }
        }
        return $out;
    }
}
