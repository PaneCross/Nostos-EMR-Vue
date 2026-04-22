<?php

// ─── FhirMetadataController ──────────────────────────────────────────────────
// Phase 11 (MVP roadmap). Serves the two FHIR conformance endpoints an
// external SMART App Launch 2.0 client needs before it can start talking:
//
//   GET /fhir/R4/metadata                       → CapabilityStatement
//   GET /fhir/R4/.well-known/smart-configuration → SMART server metadata
//
// Both endpoints are unauthenticated (public) per FHIR/SMART conventions.
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FhirMetadataController extends Controller
{
    /**
     * GET /fhir/R4/metadata
     * Returns a FHIR R4 CapabilityStatement describing supported resources,
     * interactions, and SMART security endpoints.
     */
    public function capabilityStatement(Request $request): JsonResponse
    {
        $base = rtrim($request->getSchemeAndHttpHost(), '/');

        $supportedResources = [
            ['type' => 'Patient',             'interactions' => ['read']],
            ['type' => 'Observation',         'interactions' => ['search-type']],
            ['type' => 'MedicationRequest',   'interactions' => ['search-type']],
            ['type' => 'Condition',           'interactions' => ['search-type']],
            ['type' => 'AllergyIntolerance',  'interactions' => ['search-type']],
            ['type' => 'CarePlan',            'interactions' => ['search-type']],
            ['type' => 'Appointment',         'interactions' => ['search-type']],
            ['type' => 'Immunization',        'interactions' => ['search-type']],
            ['type' => 'Procedure',           'interactions' => ['search-type']],
            ['type' => 'Encounter',           'interactions' => ['search-type']],
            ['type' => 'DiagnosticReport',    'interactions' => ['search-type']],
            ['type' => 'Practitioner',        'interactions' => ['read', 'search-type']],
            ['type' => 'Organization',        'interactions' => ['read', 'search-type']],
        ];

        $resourcesBlock = array_map(function (array $r) {
            return [
                'type'         => $r['type'],
                'interaction'  => array_map(fn ($i) => ['code' => $i], $r['interactions']),
                'searchParam'  => $r['type'] === 'Patient' || $r['type'] === 'Organization' || $r['type'] === 'Practitioner'
                    ? [['name' => '_id', 'type' => 'token']]
                    : [['name' => 'patient', 'type' => 'reference']],
            ];
        }, $supportedResources);

        $statement = [
            'resourceType' => 'CapabilityStatement',
            'status'       => 'active',
            'date'         => now()->toIso8601String(),
            'publisher'    => 'NostosEMR',
            'kind'         => 'instance',
            'software'     => [
                'name'    => 'NostosEMR Vue',
                'version' => '0.0.1',
            ],
            'implementation' => [
                'description' => 'NostosEMR FHIR R4 API (SMART App Launch 2.0)',
                'url'         => $base . '/fhir/R4',
            ],
            'fhirVersion' => '4.0.1',
            'format'      => ['application/fhir+json', 'json'],
            'rest'        => [[
                'mode'     => 'server',
                'security' => [
                    'cors' => false,
                    'service' => [[
                        'coding' => [[
                            'system'  => 'http://terminology.hl7.org/CodeSystem/restful-security-service',
                            'code'    => 'SMART-on-FHIR',
                            'display' => 'SMART-on-FHIR',
                        ]],
                    ]],
                    'extension' => [[
                        'url'       => 'http://fhir-registry.smarthealthit.org/StructureDefinition/oauth-uris',
                        'extension' => [
                            ['url' => 'authorize',  'valueUri' => $base . '/fhir/R4/auth/authorize'],
                            ['url' => 'token',      'valueUri' => $base . '/fhir/R4/auth/token'],
                            ['url' => 'introspect', 'valueUri' => $base . '/fhir/R4/auth/introspect'],
                            ['url' => 'revoke',     'valueUri' => $base . '/fhir/R4/auth/revoke'],
                        ],
                    ]],
                ],
                'resource' => $resourcesBlock,
            ]],
        ];

        return response()->json($statement, 200, [
            'Content-Type' => 'application/fhir+json',
        ], JSON_UNESCAPED_SLASHES);
    }

    /**
     * GET /fhir/R4/.well-known/smart-configuration
     * SMART App Launch 2.0 server metadata discovery document.
     */
    public function smartConfiguration(Request $request): JsonResponse
    {
        $base = rtrim($request->getSchemeAndHttpHost(), '/');

        return response()->json([
            'issuer'                         => $base,
            'authorization_endpoint'         => $base . '/fhir/R4/auth/authorize',
            'token_endpoint'                 => $base . '/fhir/R4/auth/token',
            'introspection_endpoint'         => $base . '/fhir/R4/auth/introspect',
            'revocation_endpoint'            => $base . '/fhir/R4/auth/revoke',
            'token_endpoint_auth_methods_supported' => ['client_secret_basic', 'client_secret_post', 'none'],
            'scopes_supported'               => [
                'launch', 'launch/patient', 'offline_access', 'openid', 'fhirUser',
                'patient/*.read', 'user/*.read', 'system/*.read',
                'patient/Patient.read', 'patient/Observation.read',
                'patient/MedicationRequest.read', 'patient/Condition.read',
                'patient/AllergyIntolerance.read', 'patient/CarePlan.read',
                'patient/Appointment.read', 'patient/Immunization.read',
                'patient/Procedure.read', 'patient/Encounter.read',
                'patient/DiagnosticReport.read',
                'user/Practitioner.read', 'user/Organization.read',
            ],
            'response_types_supported'       => ['code'],
            'grant_types_supported'          => ['authorization_code', 'client_credentials'],
            'code_challenge_methods_supported' => ['S256'],
            'capabilities'                   => [
                'launch-standalone',
                'client-public',
                'client-confidential-symmetric',
                'context-standalone-patient',
                'permission-patient',
                'permission-user',
                'permission-v2',
                'sso-openid-connect',
            ],
        ], 200, [
            'Content-Type' => 'application/json',
        ], JSON_UNESCAPED_SLASHES);
    }
}
