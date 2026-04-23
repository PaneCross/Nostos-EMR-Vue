<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CommitteeMember extends Model
{
    protected $table = 'emr_committee_members';

    public const ROLES = ['chair', 'vice_chair', 'secretary', 'member'];

    protected $fillable = [
        'committee_id', 'user_id', 'external_name',
        'role', 'term_start', 'term_end', 'voting_member',
    ];

    protected $casts = [
        'term_start'    => 'date',
        'term_end'      => 'date',
        'voting_member' => 'boolean',
    ];

    public function committee(): BelongsTo { return $this->belongsTo(Committee::class); }
    public function user(): BelongsTo      { return $this->belongsTo(User::class); }
}
