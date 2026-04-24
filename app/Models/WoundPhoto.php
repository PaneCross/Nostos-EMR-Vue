<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WoundPhoto extends Model
{
    protected $table = 'emr_wound_photos';

    protected $fillable = [
        'tenant_id', 'wound_id', 'document_id',
        'taken_at', 'taken_by_user_id', 'notes',
    ];

    protected $casts = ['taken_at' => 'datetime'];

    public function wound(): BelongsTo    { return $this->belongsTo(WoundRecord::class, 'wound_id'); }
    public function document(): BelongsTo { return $this->belongsTo(Document::class); }
    public function takenBy(): BelongsTo  { return $this->belongsTo(User::class, 'taken_by_user_id'); }

    public function scopeForTenant($q, int $t) { return $q->where('tenant_id', $t); }
}
