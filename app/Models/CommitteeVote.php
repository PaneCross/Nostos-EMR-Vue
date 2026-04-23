<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CommitteeVote extends Model
{
    protected $table = 'emr_committee_votes';

    public const OUTCOMES = ['passed', 'failed', 'tabled', 'pending'];

    protected $fillable = [
        'meeting_id', 'motion_text',
        'votes_yes', 'votes_no', 'votes_abstain',
        'outcome', 'notes',
    ];

    protected $casts = [
        'votes_yes'     => 'integer',
        'votes_no'      => 'integer',
        'votes_abstain' => 'integer',
    ];

    public function meeting(): BelongsTo { return $this->belongsTo(CommitteeMeeting::class, 'meeting_id'); }

    public function total(): int { return $this->votes_yes + $this->votes_no + $this->votes_abstain; }
}
