<?php

// ─── ApiToken ─────────────────────────────────────────────────────────────────
// FHIR R4 API bearer tokens for external system access.
//
// Tokens are stored as SHA-256 hashes. The plaintext token is only shown once
// at creation — NostosEMR cannot recover it after that.
//
// Used by FhirAuthMiddleware to authenticate incoming /fhir/R4/* requests.
// All reads via FHIR API are logged to shared_audit_logs with source='fhir_api'.
//
// Scope format: e.g. ["patient.read", "observation.read"]
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ApiToken extends Model
{
    use HasFactory;

    protected $table = 'emr_api_tokens';

    /** All supported FHIR resource scopes. */
    public const SCOPES = [
        'patient.read',
        'observation.read',
        'medication.read',
        'condition.read',
        'allergy.read',
        'careplan.read',
        'appointment.read',
        'immunization.read',
        'procedure.read',
        'encounter.read',
        'diagnosticreport.read',
        'practitioner.read',
        'organization.read',
    ];

    protected $fillable = [
        'user_id',
        'tenant_id',
        'token',
        'scopes',
        'name',
        'last_used_at',
        'expires_at',
    ];

    protected $casts = [
        'scopes'      => 'array',
        'last_used_at'=> 'datetime',
        'expires_at'  => 'datetime',
    ];

    // ── Relations ─────────────────────────────────────────────────────────────

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Hash a plaintext token for storage/lookup.
     * Uses SHA-256 — fast enough for internal lookup, not suitable for passwords.
     */
    public static function hashToken(string $plaintext): string
    {
        return hash('sha256', $plaintext);
    }

    /**
     * Find a token record by its plaintext value.
     * Returns null if not found or expired.
     */
    public static function findByToken(string $plaintext): ?self
    {
        $hashed = self::hashToken($plaintext);

        return self::where('token', $hashed)
            ->where(function ($q) {
                $q->whereNull('expires_at')
                  ->orWhere('expires_at', '>', now());
            })
            ->first();
    }

    /** True if the token grants the requested scope.
     *
     * Accepts both legacy dot-scopes (e.g. "patient.read") and SMART App
     * Launch 2.0 scope notation (e.g. "patient/Patient.read",
     * "system/*.read", "user/*.read"). When the token carries a SMART-style
     * wildcard scope, it grants access to all legacy dot-scopes.
     */
    public function hasScope(string $scope): bool
    {
        $granted = (array) $this->scopes;

        // Direct match
        if (in_array($scope, $granted, true)) {
            return true;
        }

        // Legacy → SMART equivalents
        $smartEquivalents = self::smartEquivalentsFor($scope);
        foreach ($smartEquivalents as $eq) {
            if (in_array($eq, $granted, true)) {
                return true;
            }
        }

        // Wildcard SMART scopes
        foreach ($granted as $g) {
            // system/*.read grants all .read scopes
            if (preg_match('#^(patient|user|system)/\*\.read$#', (string) $g)) {
                if (str_ends_with($scope, '.read')) return true;
            }
            // patient/Patient.read etc. — exact FHIR-resource match
            if (preg_match('#^(patient|user|system)/(\w+)\.(read|write|\*)$#', (string) $g, $m)) {
                [$_, $ctx, $resource, $op] = $m;
                $legacy = self::smartResourceToLegacy($resource);
                if ($legacy !== null && ($legacy . '.read') === $scope && ($op === 'read' || $op === '*')) {
                    return true;
                }
            }
        }

        return false;
    }

    /** Map a legacy scope to its SMART-style equivalents. */
    public static function smartEquivalentsFor(string $legacyScope): array
    {
        if (!str_ends_with($legacyScope, '.read')) return [];
        $resource = substr($legacyScope, 0, -5); // strip ".read"
        $fhirResource = self::legacyToSmartResource($resource);
        if ($fhirResource === null) return [];

        return [
            "patient/{$fhirResource}.read",
            "user/{$fhirResource}.read",
            "system/{$fhirResource}.read",
            "patient/{$fhirResource}.*",
            "user/{$fhirResource}.*",
            "system/{$fhirResource}.*",
        ];
    }

    /** Legacy scope resource prefix → canonical FHIR resource type name. */
    public static function legacyToSmartResource(string $legacy): ?string
    {
        return match ($legacy) {
            'patient'          => 'Patient',
            'observation'      => 'Observation',
            'medication'       => 'MedicationRequest',
            'condition'        => 'Condition',
            'allergy'          => 'AllergyIntolerance',
            'careplan'         => 'CarePlan',
            'appointment'      => 'Appointment',
            'immunization'     => 'Immunization',
            'procedure'        => 'Procedure',
            'encounter'        => 'Encounter',
            'diagnosticreport' => 'DiagnosticReport',
            'practitioner'     => 'Practitioner',
            'organization'     => 'Organization',
            default            => null,
        };
    }

    /** FHIR resource type name → legacy scope resource prefix. */
    public static function smartResourceToLegacy(string $resource): ?string
    {
        return match ($resource) {
            'Patient'           => 'patient',
            'Observation'       => 'observation',
            'MedicationRequest' => 'medication',
            'Condition'         => 'condition',
            'AllergyIntolerance'=> 'allergy',
            'CarePlan'          => 'careplan',
            'Appointment'       => 'appointment',
            'Immunization'      => 'immunization',
            'Procedure'         => 'procedure',
            'Encounter'         => 'encounter',
            'DiagnosticReport'  => 'diagnosticreport',
            'Practitioner'      => 'practitioner',
            'Organization'      => 'organization',
            default             => null,
        };
    }

    /** Mark this token as used right now. Avoid overriding Model::touch() — use markUsed() instead. */
    public function markUsed(): bool
    {
        return $this->update(['last_used_at' => now()]);
    }
}
