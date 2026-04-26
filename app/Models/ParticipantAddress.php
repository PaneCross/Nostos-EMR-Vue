<?php

// ─── ParticipantAddress ───────────────────────────────────────────────────────
// One physical address belonging to a participant (the enrolled elderly
// person receiving PACE — Programs of All-Inclusive Care for the Elderly —
// services). A participant can have multiple addresses (home, mailing,
// temporary, caregiver) tracked over time via effective_date / end_date.
//
// `is_primary` marks the current canonical address shown on the chart header
// and used for transportation pickup. Soft-deletes preserve historical rows.
//
// Notable rules:
//  - Address is PHI (Protected Health Information) under HIPAA — tenant-scoped
//    via the parent Participant; never query without that join.
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class ParticipantAddress extends Model
{
    use SoftDeletes;

    protected $table = 'emr_participant_addresses';

    protected $fillable = [
        'participant_id', 'address_type',
        'street', 'unit', 'city', 'state', 'zip',
        'notes', 'is_primary', 'effective_date', 'end_date',
    ];

    protected $casts = [
        'is_primary'     => 'boolean',
        'effective_date' => 'date',
        'end_date'       => 'date',
    ];

    public function participant(): BelongsTo
    {
        return $this->belongsTo(Participant::class, 'participant_id');
    }

    public function oneLiner(): string
    {
        $parts = array_filter([$this->street, $this->unit, $this->city, $this->state, $this->zip]);
        return implode(', ', $parts);
    }
}
