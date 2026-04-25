<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DmeIssuance extends Model
{
    use HasFactory;

    protected $table = 'emr_dme_issuances';

    public const RETURN_CONDITIONS = ['good', 'damaged', 'lost'];

    protected $fillable = [
        'tenant_id', 'dme_item_id', 'participant_id',
        'issued_at', 'issued_by_user_id',
        'expected_return_at', 'returned_at', 'returned_to_user_id',
        'return_condition', 'issue_notes', 'return_notes',
    ];

    protected $casts = [
        'issued_at'          => 'date',
        'expected_return_at' => 'date',
        'returned_at'        => 'date',
    ];

    public function item(): BelongsTo        { return $this->belongsTo(DmeItem::class, 'dme_item_id'); }
    public function participant(): BelongsTo { return $this->belongsTo(Participant::class); }
    public function issuer(): BelongsTo      { return $this->belongsTo(User::class, 'issued_by_user_id'); }

    public function isOpen(): bool { return $this->returned_at === null; }
}
