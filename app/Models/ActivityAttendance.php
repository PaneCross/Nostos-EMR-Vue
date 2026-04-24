<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ActivityAttendance extends Model
{
    protected $table = 'emr_activity_attendances';

    public const STATUSES = ['attended', 'declined', 'unable_due_to_illness', 'absent'];
    public const ENGAGEMENT = ['low', 'med', 'high'];

    protected $fillable = [
        'tenant_id', 'activity_event_id', 'participant_id',
        'attendance_status', 'engagement_level', 'notes', 'recorded_by_user_id',
    ];

    public function event(): BelongsTo       { return $this->belongsTo(ActivityEvent::class, 'activity_event_id'); }
    public function participant(): BelongsTo { return $this->belongsTo(Participant::class); }

    public function scopeForTenant($q, int $t) { return $q->where('tenant_id', $t); }
}
