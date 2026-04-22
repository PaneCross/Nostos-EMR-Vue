<?php

// ─── TrrFile ──────────────────────────────────────────────────────────────────
// CMS Transaction Reply Report file upload record.
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TrrFile extends Model
{
    use HasFactory;

    protected $table = 'emr_trr_files';

    public const STATUS_RECEIVED    = 'received';
    public const STATUS_PARSING     = 'parsing';
    public const STATUS_PARSED      = 'parsed';
    public const STATUS_PARSE_ERROR = 'parse_error';

    protected $fillable = [
        'tenant_id',
        'uploaded_by_user_id',
        'contract_id',
        'original_filename',
        'storage_path',
        'file_size_bytes',
        'received_at',
        'parsed_at',
        'status',
        'record_count',
        'rejected_count',
        'accepted_count',
        'parse_error_message',
    ];

    protected $casts = [
        'received_at' => 'datetime',
        'parsed_at'   => 'datetime',
    ];

    public function tenant(): BelongsTo     { return $this->belongsTo(Tenant::class); }
    public function uploadedBy(): BelongsTo { return $this->belongsTo(User::class, 'uploaded_by_user_id'); }
    public function records(): HasMany      { return $this->hasMany(TrrRecord::class); }
}
