# Breach Notification Runbook

**Audience:** Privacy Officer + IT Admin lead + Executive.
**Regulatory driver:** HIPAA Breach Notification Rule §164.400-414.

## Definition

A **breach** is an impermissible use or disclosure of PHI that compromises security or privacy. Not all PHI exposure is a reportable breach — HIPAA has a 4-factor risk assessment.

## The clock

**From the moment a breach is discovered:**
- **≤ 60 days:** notify affected individuals (required for all breaches > 500 individuals; required for all in most cases)
- **≤ 60 days:** notify HHS (if >500 in a state, notify state media + HHS immediately; if <500, annual batch submission by March 1 of following year)
- **Tenant-specific:** state breach laws may impose shorter clocks (California 5 days; Texas varies)

## Discovery → Triage (first 24 hours)

1. **Contain**
   - If a user account is compromised: deactivate immediately (`is_active=false`)
   - If a system vector: pull affected servers offline
   - If third-party vendor: cut their API keys (`/it-admin/clearinghouse-config`, `/it-admin/users` for API tokens)
2. **Preserve evidence**
   - Do NOT delete audit logs (they're append-only anyway, but don't rotate)
   - Snapshot affected database + filesystem state
   - Preserve relevant Reverb/WebSocket logs, web server logs
3. **Assemble team**
   - Privacy Officer (lead)
   - IT Admin
   - Legal counsel
   - Executive (decision authority on notification)
4. **Initial audit-log pull**
   - `/it-admin/audit?action=break_glass.requested,participant.updated,fhir.read` with timeframe around the event
   - Export CSV; preserve

## 4-factor risk assessment (HIPAA §164.402)

Assess whether PHI was compromised:
1. **Nature + extent** of PHI involved — 18 HIPAA identifiers, financial info, clinical specificity
2. **Unauthorized person** who used/received PHI — internal employee with separate legitimate access? or external threat actor?
3. **Whether PHI was actually acquired or viewed** — not just "in transit"
4. **Mitigation extent** — was data recovered? destroyed? encrypted? confidential agreement with the receiver?

Document the assessment with signatures.

## Notification content (per 164.404)

To each affected individual:
- **Brief description** of what happened, date, discovery date
- **Types of information** involved
- **Steps individuals should take** to protect themselves
- **What you're doing** to investigate + mitigate + prevent recurrence
- **Contact info** for more info (phone / email / postal)

Method: first-class mail; email if individual has agreed to electronic notice. Substitute notice on website if >10 individuals cannot be reached.

## Media notification (if >500 in a state)

Prominent media outlets covering the state. Contents: same as individual notice.

## HHS notification

- **>500 individuals**: within 60 days via https://ocrportal.hhs.gov/ocr/breach/breach_form.jsf
- **<500 individuals**: annually by March 1 covering prior calendar year

## Documentation retention

All breach-response documentation: 6 years. Store in the tenant's privacy-officer record + reference in `/it-admin/security` with a "Breach incident" entry.

## Post-mortem

Within 30 days of containment:
- Root cause analysis (technical + process)
- What worked + what didn't
- Specific remediation actions + owners + deadlines
- Update policies / training if process gaps found
- Report to governing board + QAPI committee

## Common scenarios

| Scenario | Action |
|---|---|
| Employee accessed chart outside their scope (curiosity) | Break-glass review catches; discipline per HR; no notification if no external disclosure |
| Misdirected email to wrong external party | 4-factor assessment; usually notifiable if external recipient |
| Lost / stolen unencrypted laptop with PHI | Notifiable; encryption requirement is why this shouldn't happen |
| Phishing compromise of employee OTP | Check audit logs for the session; if charts accessed, notify those individuals |
| Vendor breach (clearinghouse, DrFirst) | Vendor notifies us per BAA; we notify individuals + HHS |
| Accidental upload of wrong participant data to CSV import | Rollback the import; 4-factor assessment; usually not notifiable if caught before commit |

## External counsel

Engage healthcare-privacy counsel before notification goes out. The letter wording matters legally.
