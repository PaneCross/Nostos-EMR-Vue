<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>NostosEMR FHIR R4 API: Developer Guide</title>
<style>
    :root { color-scheme: light dark; }
    body { font-family: -apple-system, Segoe UI, Roboto, sans-serif; max-width: 860px; margin: 2rem auto; padding: 0 1rem; line-height: 1.5; color: #0f172a; }
    @media (prefers-color-scheme: dark) { body { background: #0f172a; color: #e2e8f0; } a { color: #93c5fd; } code, pre { background: #1e293b; color: #e2e8f0; } }
    h1 { margin-bottom: 0.25rem; }
    h2 { margin-top: 2rem; border-bottom: 1px solid #cbd5e1; padding-bottom: 0.25rem; }
    code { background: #f1f5f9; padding: 1px 4px; border-radius: 3px; font-family: SFMono-Regular, Consolas, monospace; font-size: 0.95em; }
    pre { background: #f1f5f9; padding: 0.75rem 1rem; border-radius: 6px; overflow-x: auto; font-family: SFMono-Regular, Consolas, monospace; font-size: 0.9em; }
    table { border-collapse: collapse; margin: 1rem 0; }
    table td, table th { border: 1px solid #cbd5e1; padding: 4px 10px; text-align: left; font-size: 0.95em; }
    .tag { display: inline-block; background: #dbeafe; color: #1e40af; padding: 2px 8px; border-radius: 999px; font-size: 0.8em; margin-right: 6px; }
    .warn { background: #fef3c7; color: #78350f; padding: 0.75rem 1rem; border-left: 3px solid #f59e0b; border-radius: 4px; margin: 1rem 0; }
</style>
</head>
<body>

<h1>NostosEMR FHIR R4 API</h1>
<p><em>SMART App Launch 2.0 conformant · Read-only MVP · Phase 11 (2026-04-22)</em></p>

<div class="warn">
    <strong>Status:</strong> Read-only. Write operations ($everything, PUT, POST, DELETE) are not yet supported.
    Bulk export ($export) is planned for a later phase.
</div>

<h2>1. Discovery endpoints</h2>
<p>Start here. Both are unauthenticated.</p>
<table>
    <tr><th>URL</th><th>Purpose</th></tr>
    <tr><td><code>GET /fhir/R4/metadata</code></td><td>FHIR R4 <code>CapabilityStatement</code></td></tr>
    <tr><td><code>GET /fhir/R4/.well-known/smart-configuration</code></td><td>SMART App Launch 2.0 server metadata</td></tr>
</table>

<h2>2. SMART App Launch 2.0 flows</h2>

<h3>2.1 Authorization Code (with PKCE): for browser/mobile apps</h3>
<pre>1. App redirects user:
   GET /fhir/R4/auth/authorize
       ?response_type=code
       &client_id=&lt;your client_id&gt;
       &redirect_uri=&lt;registered URI&gt;
       &scope=patient/Patient.read%20patient/Observation.read%20launch/patient
       &state=&lt;csrf nonce&gt;
       &code_challenge=&lt;S256 of verifier&gt;
       &code_challenge_method=S256

2. User logs in to NostosEMR + approves scopes.

3. NostosEMR redirects back:
   &lt;redirect_uri&gt;?code=&lt;short-lived auth code&gt;&amp;state=&lt;echo&gt;

4. App exchanges code:
   POST /fhir/R4/auth/token
       Content-Type: application/x-www-form-urlencoded
       grant_type=authorization_code
       &amp;code=&lt;code&gt;
       &amp;redirect_uri=&lt;same as above&gt;
       &amp;client_id=&lt;your client_id&gt;
       &amp;code_verifier=&lt;original PKCE verifier&gt;

5. Response:
   { "access_token": "...", "token_type": "Bearer", "expires_in": 3600,
     "scope": "patient/Patient.read patient/Observation.read",
     "patient": "42" }
</pre>

<h3>2.2 Client Credentials: for backend-to-backend integrations</h3>
<pre>POST /fhir/R4/auth/token
    Authorization: Basic base64(client_id:client_secret)
    Content-Type: application/x-www-form-urlencoded
    grant_type=client_credentials
    &amp;scope=system/Patient.read%20system/Observation.read
</pre>

<h2>3. Calling a FHIR resource</h2>
<pre>GET /fhir/R4/Patient/42
    Authorization: Bearer &lt;access_token&gt;
    Accept: application/fhir+json

GET /fhir/R4/Observation?patient=42
    Authorization: Bearer &lt;access_token&gt;
</pre>

<h2>4. Supported resources</h2>
<table>
    <tr><th>Resource</th><th>Read by ID</th><th>Search</th><th>Required scope</th></tr>
    <tr><td>Patient</td><td>✓</td><td>-</td><td><code>patient/Patient.read</code></td></tr>
    <tr><td>Observation</td><td>-</td><td>?patient=</td><td><code>patient/Observation.read</code></td></tr>
    <tr><td>MedicationRequest</td><td>-</td><td>?patient=</td><td><code>patient/MedicationRequest.read</code></td></tr>
    <tr><td>Condition</td><td>-</td><td>?patient=</td><td><code>patient/Condition.read</code></td></tr>
    <tr><td>AllergyIntolerance</td><td>-</td><td>?patient=</td><td><code>patient/AllergyIntolerance.read</code></td></tr>
    <tr><td>CarePlan</td><td>-</td><td>?patient=</td><td><code>patient/CarePlan.read</code></td></tr>
    <tr><td>Appointment</td><td>-</td><td>?patient=</td><td><code>patient/Appointment.read</code></td></tr>
    <tr><td>Immunization</td><td>-</td><td>?patient=</td><td><code>patient/Immunization.read</code></td></tr>
    <tr><td>Procedure</td><td>-</td><td>?patient=</td><td><code>patient/Procedure.read</code></td></tr>
    <tr><td>Encounter</td><td>-</td><td>?patient=</td><td><code>patient/Encounter.read</code></td></tr>
    <tr><td>DiagnosticReport</td><td>-</td><td>?patient=</td><td><code>patient/DiagnosticReport.read</code></td></tr>
    <tr><td>Practitioner</td><td>✓</td><td>?name=</td><td><code>user/Practitioner.read</code></td></tr>
    <tr><td>Organization</td><td>✓</td><td>-</td><td><code>user/Organization.read</code></td></tr>
</table>

<h2>5. Scope notation: legacy and SMART both accepted</h2>
<p>The following are all equivalent and will satisfy a <code>patient.read</code> scope check:</p>
<pre>patient.read                 ← legacy (still accepted)
patient/Patient.read         ← SMART App Launch 2.0
patient/Patient.*            ← SMART with operation wildcard
patient/*.read               ← SMART with resource wildcard
user/*.read
system/*.read</pre>

<h2>6. Auth errors</h2>
<p>Errors return FHIR <code>OperationOutcome</code> JSON with <code>Content-Type: application/fhir+json</code>:</p>
<ul>
    <li><code>401</code>: missing / invalid / expired Bearer token</li>
    <li><code>403</code>: token valid but scope insufficient</li>
    <li><code>404</code>: resource not found OR belongs to another tenant (FHIR convention: we never confirm cross-tenant existence)</li>
    <li><code>400</code>: missing required query parameter (e.g. <code>?patient=</code>)</li>
</ul>

<h2>7. Audit logging</h2>
<p>Every authenticated FHIR read is recorded in <code>shared_audit_logs</code> with <code>action=fhir.read</code>, the scope used, the participant accessed, and the token's user_id + tenant_id. OAuth events (<code>fhir.oauth_code_issued</code>, <code>fhir.oauth_token_issued</code>, <code>fhir.oauth_token_revoked</code>) are also logged.</p>

<h2>8. Rate limits and pagination</h2>
<p>Not yet enforced. Search responses return a FHIR <code>Bundle</code> with all matching entries in one page. When data volumes grow, we'll add a <code>next</code> link per FHIR pagination spec.</p>

<h2>9. Getting credentials</h2>
<p>Contact your tenant's IT administrator. Registered OAuth clients are per-tenant, provisioned by IT admin with a list of allowed scopes and registered redirect URIs.</p>

</body>
</html>
