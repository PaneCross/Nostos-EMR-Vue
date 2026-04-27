<?php

// ─── SamlController ──────────────────────────────────────────────────────────
// Phase 15.2 : SAML 2.0 SP endpoint scaffold. Publishes SP metadata and
// exposes stubs for login / ACS / SLO. Real assertion handling requires
// installing `laravel-saml2` or equivalent PHP SAML library : documented
// in the honest-label response.
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\SamlIdentityProvider;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class SamlController extends Controller
{
    /** GET /saml/{tenantId}/metadata : SP entity descriptor XML. */
    public function metadata(int $tenantId, Request $request): Response
    {
        $idp = SamlIdentityProvider::forTenant($tenantId)->active()->first();
        $base = rtrim($request->getSchemeAndHttpHost(), '/');
        $spEntityId = $idp?->sp_entity_id ?: ($base . "/saml/{$tenantId}/metadata");
        $acsUrl = $base . "/saml/{$tenantId}/acs";
        $sloUrl = $base . "/saml/{$tenantId}/slo";

        $xml = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<EntityDescriptor xmlns="urn:oasis:names:tc:SAML:2.0:metadata" entityID="{$spEntityId}">
  <SPSSODescriptor AuthnRequestsSigned="false" WantAssertionsSigned="true"
      protocolSupportEnumeration="urn:oasis:names:tc:SAML:2.0:protocol">
    <NameIDFormat>urn:oasis:names:tc:SAML:1.1:nameid-format:emailAddress</NameIDFormat>
    <AssertionConsumerService Binding="urn:oasis:names:tc:SAML:2.0:bindings:HTTP-POST"
        Location="{$acsUrl}" index="0" isDefault="true"/>
    <SingleLogoutService Binding="urn:oasis:names:tc:SAML:2.0:bindings:HTTP-Redirect"
        Location="{$sloUrl}"/>
  </SPSSODescriptor>
</EntityDescriptor>
XML;

        return response($xml, 200, ['Content-Type' => 'application/samlmetadata+xml']);
    }

    /** GET /saml/{tenantId}/login : initiate SSO (stub). */
    public function login(int $tenantId): JsonResponse
    {
        $idp = SamlIdentityProvider::forTenant($tenantId)->active()->first();
        if (! $idp) {
            return response()->json([
                'error' => 'no_idp_configured',
                'message' => 'No active SAML IdP for tenant ' . $tenantId,
            ], 404);
        }
        return response()->json([
            'scaffold'    => true,
            'idp_entity'  => $idp->entity_id,
            'sso_url'     => $idp->sso_url,
            'honest_label'=> 'SAML SP is scaffolded. Install `aacotroneo/laravel-saml2` (or equivalent) '
                            . 'and wire IdP-specific AuthnRequest generation. This endpoint currently '
                            . 'returns configuration only : it does NOT redirect the browser to the IdP.',
        ], 501);
    }

    /** POST /saml/{tenantId}/acs : assertion consumer (stub). */
    public function acs(Request $request, int $tenantId): JsonResponse
    {
        AuditLog::record(
            action: 'saml.acs_stub_hit',
            tenantId: $tenantId,
            description: 'SAML ACS endpoint hit (scaffold : not processing assertion)',
        );
        return response()->json([
            'scaffold'     => true,
            'honest_label' => 'SAML ACS not yet implemented. Real implementation parses the SAMLResponse '
                            . 'POST body, verifies signature against the IdP x509 cert, extracts the NameID '
                            . 'and mapped attributes, and resolves/creates the shared_users row before starting a session.',
        ], 501);
    }

    /** GET /saml/{tenantId}/slo : single logout (stub). */
    public function slo(Request $request, int $tenantId): JsonResponse
    {
        return response()->json([
            'scaffold'     => true,
            'honest_label' => 'SAML Single Logout not yet implemented.',
        ], 501);
    }
}
