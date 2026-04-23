# SOC 2 Readiness — Guidance for First Paying Client

**Audience:** Executive + IT Admin lead.
**When:** Once a signed LOI / contract with a paying customer is in hand.

SOC 2 is not a one-time event; it's an ongoing evidence-collection program. The smart path:

## Step 1 — Pick a framework

SOC 2 Type I (point-in-time design review) costs ~$20-40k and takes ~4-6 months.
SOC 2 Type II (6-12 month operational effectiveness) costs ~$40-80k.

For a healthcare buyer, Type II is usually the ask. But **Type I gets you in the door** for the first couple of sales cycles.

## Step 2 — Engage an auditor + a readiness tool

- **Auditor options:** Schellman, Prescient Assurance, Johanson Group, A-LIGN, Coalfire. Healthcare-specific: Drummond, HITRUST-aligned firms.
- **Readiness tools:** Vanta, Drata, Secureframe, Thoropass — automate 60-70% of evidence collection against cloud infrastructure + employee access.

Recommended: Drata or Vanta for evidence automation, paired with A-LIGN or Schellman as the auditor.

## Step 3 — Trust service criteria in scope

All 5 for healthcare:
- **Security** (required baseline)
- **Availability**
- **Processing Integrity**
- **Confidentiality**
- **Privacy**

Privacy is the additive one most EMRs need to demonstrate — it overlaps heavily with HIPAA.

## Step 4 — Controls we already have

Many SOC 2 control-objective categories map directly to what's already built:

| Control objective | Current state in NostosEMR |
|---|---|
| Access control | OTP auth + RBAC (department + role + designation) |
| Multi-factor authentication | OTP-only via email (email is the second factor; for higher-assurance, integrate with SAML IdP requiring MFA — Phase 15.2) |
| Encryption at rest | PostgreSQL disk encryption at hosting layer; sensitive fields (medicare_id, medicaid_id, member_id, BIN/PCN, credentials_json) encrypted at Eloquent cast layer |
| Encryption in transit | HTTPS enforced at load balancer |
| Logging + monitoring | `shared_audit_logs` (immutable append-only) covers auth, chart access, break-glass, FHIR reads, every write |
| Incident response | `breach-notification-runbook.md` + monthly break-glass review |
| Change management | Git + CI (tests must pass), migrations versioned |
| Backup + DR | `dr-test-plan.md` |
| Vendor risk | `baa-template-workflow.md` |
| Privacy | 3 public policy surfaces (info-blocking, NPP, acceptable use) |

## Step 5 — Controls that need operational work

- **Employee onboarding + offboarding checklist** (code vs. production access separation; deprovisioning on termination within 24h)
- **Vulnerability management** (continuous scanning; patch cadence)
- **Change-management ticket system** (Jira / Linear / GitHub Projects — not just git)
- **Vendor annual review** (tracked in BAA table + recurring calendar)
- **Annual SRA** (Security Risk Assessment — already partially tracked via `/it-admin/security`)
- **Annual pen test** (`pen-test-plan.md`)
- **Annual DR test** (`dr-test-plan.md`)

## Step 6 — Evidence collection runbook

Once Type I is contracted:
1. Drata / Vanta integrates with AWS / GCP / GitHub / Slack / HR system
2. Auto-collects: user list, access reviews, vulnerability scans, backup completion, incident records
3. You + IT admin upload: policy documents, training attestations, vendor BAAs, annual-review signatures
4. Auditor reviews + requests samples
5. Type I report issues ~4 weeks after fieldwork

## Step 7 — Ongoing maintenance

After Type I:
- Continuous evidence collection
- 6-month micro-audits with your readiness vendor
- Type II fieldwork ~10-12 months later
- Annual re-issuance thereafter

## Budget estimate

Year 1 (Type I): ~$45-70k all-in (auditor + readiness tool + internal time)
Year 2 (Type II): ~$55-90k
Year 3+: ~$40-60k annual maintenance

This is a real cost. Only start once the first paying client's LOI makes it economic.
