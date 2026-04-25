<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CmsAuditUniverseAttempt extends Model
{
    protected $table = 'emr_cms_audit_universe_attempts';

    public const UNIVERSES = ['sdr', 'grievances', 'disenrollments', 'appeals'];
    public const MAX_ATTEMPTS = 3;

    protected $fillable = [
        'tenant_id', 'audit_id', 'universe', 'attempt_number',
        'passed_validation', 'validation_errors', 'row_count',
        'period_start', 'period_end', 'exported_by_user_id',
    ];

    protected $casts = [
        'passed_validation' => 'boolean',
        'validation_errors' => 'array',
        'period_start'      => 'date',
        'period_end'        => 'date',
    ];

    public function scopeForTenant($q, int $t)            { return $q->where('tenant_id', $t); }
    public function scopeForAudit($q, string $auditId)    { return $q->where('audit_id', $auditId); }
    public function scopeForUniverse($q, string $u)       { return $q->where('universe', $u); }
}
