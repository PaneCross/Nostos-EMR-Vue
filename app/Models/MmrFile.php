<?php

// ─── MmrFile ──────────────────────────────────────────────────────────────────
// CMS Monthly Membership Report file. One row per uploaded file per period.
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MmrFile extends Model
{
    use HasFactory;

    protected $table = 'emr_mmr_files';

    public const STATUS_RECEIVED    = 'received';
    public const STATUS_PARSING     = 'parsing';
    public const STATUS_PARSED      = 'parsed';
    public const STATUS_PARSE_ERROR = 'parse_error';

    protected $fillable = [
        'tenant_id',
        'uploaded_by_user_id',
        'period_year',
        'period_month',
        'contract_id',
        'original_filename',
        'storage_path',
        'file_size_bytes',
        'received_at',
        'parsed_at',
        'status',
        'record_count',
        'discrepancy_count',
        'total_capitation_amount',
        'parse_error_message',
    ];

    protected $casts = [
        'received_at'             => 'datetime',
        'parsed_at'               => 'datetime',
        'total_capitation_amount' => 'decimal:2',
        'period_year'             => 'integer',
        'period_month'            => 'integer',
    ];

    public function tenant(): BelongsTo     { return $this->belongsTo(Tenant::class); }
    public function uploadedBy(): BelongsTo { return $this->belongsTo(User::class, 'uploaded_by_user_id'); }
    public function records(): HasMany      { return $this->hasMany(MmrRecord::class); }

    public function label(): string
    {
        return sprintf('%04d-%02d', $this->period_year, $this->period_month);
    }
}
