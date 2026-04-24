<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProResponse extends Model
{
    protected $table = 'emr_pro_responses';

    protected $fillable = [
        'tenant_id', 'participant_id', 'survey_id',
        'answers', 'aggregate_score', 'received_at', 'delivery_channel',
    ];

    protected $casts = [
        'answers'     => 'array',
        'received_at' => 'datetime',
    ];

    public function scopeForTenant($q, int $t) { return $q->where('tenant_id', $t); }
}
