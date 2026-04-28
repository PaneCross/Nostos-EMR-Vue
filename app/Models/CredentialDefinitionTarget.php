<?php

// ─── CredentialDefinitionTarget ──────────────────────────────────────────────
// One row per (definition, target_kind, target_value) tuple. OR semantics :
// a user is targeted by the parent definition if ANY of their attributes
// (department / job_title / any of their designations) matches ANY row here.
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CredentialDefinitionTarget extends Model
{
    use HasFactory;

    protected $table = 'emr_credential_definition_targets';

    public const KIND_DEPARTMENT  = 'department';
    public const KIND_JOB_TITLE   = 'job_title';
    public const KIND_DESIGNATION = 'designation';

    public const KINDS = [
        self::KIND_DEPARTMENT,
        self::KIND_JOB_TITLE,
        self::KIND_DESIGNATION,
    ];

    protected $fillable = [
        'credential_definition_id',
        'target_kind',
        'target_value',
    ];

    public function definition(): BelongsTo
    {
        return $this->belongsTo(CredentialDefinition::class, 'credential_definition_id');
    }
}
