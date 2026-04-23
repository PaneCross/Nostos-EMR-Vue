# Penetration Test Plan

**Audience:** IT Admin + security lead.
**Frequency:** Annual external pen test + continuous internal vulnerability scanning.
**Regulatory driver:** HIPAA Security Risk Assessment (SRA) §164.308(a)(1)(ii)(A); SOC 2 Trust Service Criteria; PACE Audit Protocol security section.

## Scope (for external firm)

**In scope:**
- Public web endpoints — `/login`, `/participants/*` (after auth), `/fhir/R4/*`, `/saml/*`, `/webhooks/*`
- Authentication flows — OTP login, SMART OAuth (Phase 11), break-glass access
- FHIR API — all 13 resource read endpoints + Bulk Data $export
- Multi-tenant isolation — prove tenant A cannot access tenant B data through any path
- File upload surfaces — participant photos, documents, C-CDA import, CSV data import
- Inertia + Vue frontend — XSS, CSRF
- WebSocket (Reverb) broadcasting — authorization per channel

**Out of scope:**
- Customer network / endpoints (their responsibility)
- Social engineering (unless specifically contracted)
- DDoS testing (run separately, quarterly)
- Physical security of tenant facilities

## Recommended firms

- Trail of Bits, Bishop Fox, NCC Group (top tier; expensive)
- Schellman, Coalfire (Big-4-adjacent; audit-firm integration)
- Any certified Drummond / HITRUST partner

Small / mid-tier firms: Cobalt Labs (PTaaS model), HackerOne (bug bounty approach).

## Test windows

Pen test runs against a staging environment identical to production. Never against a customer's production tenant without explicit written permission and an incident-response standby.

## Standard test checklist (minimum)

1. **OWASP Top 10** — injection, broken auth, sensitive data exposure, XXE, broken access control, security misconfiguration, XSS, insecure deserialization, known vulnerabilities, insufficient logging
2. **HIPAA-specific:**
   - Cross-tenant PHI access attempts
   - Break-glass abuse patterns
   - Audit log tampering attempts (should fail — PG rules block UPDATE/DELETE)
   - Unauthenticated FHIR + SMART scope elevation
3. **Multi-tenant tests:**
   - Swap tenant_id in URL params / JSON payloads
   - JWT / OAuth token substitution
   - Session cookie reuse across tenants
4. **File upload:**
   - Malicious CSV (formula injection, zip bombs, oversized files)
   - Malicious XML (XXE, billion-laughs)
   - Malicious image (polyglot, SVG with script)
5. **Authentication:**
   - OTP brute-force rate limiting
   - Session fixation
   - OAuth redirect_uri manipulation (must fail per Phase 11 design)
   - PKCE downgrade attempts

## Finding triage

- **Critical** (RCE, auth bypass, cross-tenant data leak): same-day fix + customer notification
- **High** (privilege escalation, sensitive-data exposure): 7-day fix window
- **Medium** (XSS, CSRF missing on non-sensitive form): 30-day fix window
- **Low** (info disclosure, best-practice gap): tracked; fixed at next opportunity

## Remediation evidence

Every finding requires:
- Code PR / configuration change
- Regression test in `tests/Feature/` or `tests/Security/`
- Re-test by the pen test firm (or signed-off retest within IT)

## SOC 2 Type I commitment path

Once first paying client lands:
1. Engage a SOC 2 auditor (Schellman, Vanta-partnered firms, or direct)
2. 6-12 month readiness window — operational evidence collection
3. Type I report (point-in-time design of controls) — ~8 months in
4. Type II (6-12 month operational effectiveness) — year 2

Type I is the minimum for most enterprise sales; Type II closes healthcare-vertical sales.

## Operational owner

IT Admin lead arranges annual pen test. Executive reviews findings + signs off remediation.
