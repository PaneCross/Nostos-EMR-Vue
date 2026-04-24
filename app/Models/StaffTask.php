<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class StaffTask extends Model
{
    protected $table = 'emr_staff_tasks';

    public const PRIORITIES = ['low', 'normal', 'high', 'urgent'];
    public const STATUSES   = ['pending', 'in_progress', 'completed', 'cancelled'];
    public const OPEN_STATUSES = ['pending', 'in_progress'];

    protected $fillable = [
        'tenant_id', 'participant_id', 'assigned_to_user_id', 'assigned_to_department',
        'created_by_user_id', 'title', 'description', 'priority', 'due_at',
        'status', 'completed_at', 'completion_note',
        'related_to_type', 'related_to_id',
    ];

    protected $casts = [
        'due_at'       => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function participant(): BelongsTo   { return $this->belongsTo(Participant::class); }
    public function assignedUser(): BelongsTo  { return $this->belongsTo(User::class, 'assigned_to_user_id'); }
    public function createdBy(): BelongsTo     { return $this->belongsTo(User::class, 'created_by_user_id'); }
    public function relatedTo(): MorphTo       { return $this->morphTo(null, 'related_to_type', 'related_to_id'); }

    public function scopeForTenant($q, int $t) { return $q->where('tenant_id', $t); }
    public function scopeOpen($q)              { return $q->whereIn('status', self::OPEN_STATUSES); }
    public function scopeOverdue($q)
    {
        return $q->whereIn('status', self::OPEN_STATUSES)
            ->whereNotNull('due_at')
            ->where('due_at', '<', now());
    }
}
