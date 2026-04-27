<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// Phase 15.4 : CSV import batch metadata.
class DataImport extends Model
{
    protected $table = 'emr_data_imports';

    public const ENTITIES = ['participants', 'problems', 'allergies', 'medications', 'care_plans', 'enrollments'];
    public const STATUSES = ['staged', 'committed', 'failed', 'cancelled'];

    protected $fillable = [
        'tenant_id', 'uploaded_by_user_id', 'entity', 'status',
        'original_filename', 'stored_path',
        'parsed_row_count', 'committed_row_count', 'error_row_count',
        'column_mapping', 'errors_json',
        'staged_at', 'committed_at',
    ];

    protected $casts = [
        'column_mapping' => 'array',
        'errors_json'    => 'array',
        'staged_at'      => 'datetime',
        'committed_at'   => 'datetime',
    ];

    public function tenant(): BelongsTo { return $this->belongsTo(Tenant::class); }

    public function scopeForTenant($q, int $tenantId) { return $q->where('tenant_id', $tenantId); }
}
