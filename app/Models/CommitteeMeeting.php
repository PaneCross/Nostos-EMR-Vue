<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CommitteeMeeting extends Model
{
    protected $table = 'emr_committee_meetings';

    public const STATUSES = ['scheduled', 'held', 'cancelled'];

    protected $fillable = [
        'committee_id', 'scheduled_date', 'location', 'status',
        'agenda', 'minutes', 'attendees_json', 'held_at',
    ];

    protected $casts = [
        'scheduled_date' => 'date',
        'held_at'        => 'datetime',
        'attendees_json' => 'array',
    ];

    public function committee(): BelongsTo { return $this->belongsTo(Committee::class); }
    public function votes(): HasMany       { return $this->hasMany(CommitteeVote::class, 'meeting_id'); }
}
