<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GoalsOfCareConversation extends Model
{
    use HasFactory;

    protected $table = 'emr_goals_of_care_conversations';

    protected $fillable = [
        'tenant_id', 'participant_id',
        'conversation_date', 'participants_present',
        'discussion_summary', 'decisions_made', 'next_steps',
        'recorded_by_user_id',
    ];

    protected $casts = ['conversation_date' => 'date'];

    public function participant(): BelongsTo { return $this->belongsTo(Participant::class); }
    public function recordedBy(): BelongsTo  { return $this->belongsTo(User::class, 'recorded_by_user_id'); }

    public function scopeForTenant($q, int $t) { return $q->where('tenant_id', $t); }
}
