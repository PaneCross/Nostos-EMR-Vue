# Break-Glass Event Review (Monthly)

**Audience:** IT Admin + QA Compliance.
**Frequency:** Monthly, first week of each month.
**Regulatory driver:** HIPAA §164.312(b) — access logging + periodic review.

## What is a break-glass event

A break-glass event is an emergency-access override where a user accesses a participant's chart that their normal RBAC wouldn't let them see. Typical trigger: after-hours emergency when the on-call provider isn't in the participant's normal care team.

Break-glass access is always time-limited (default 4 hours, max 24 hours) and writes an immutable entry to `shared_audit_logs` with `action = break_glass.requested`.

## Why it must be reviewed

- **Prevent abuse.** Break-glass is easy to use; audit keeps it honest.
- **Detect workflow gaps.** If a specific user repeatedly uses break-glass to access participants outside their normal scope, their RBAC may need adjustment (legitimate need) or they may be over-reaching (training/discipline issue).
- **CMS + HIPAA auditor ask.** "Show me the last 12 months of emergency-access events and your review notes" is a standard survey request.

## Monthly review procedure

1. **Pull events** from the last calendar month.
   - Go to `/it-admin/audit`
   - Filter `action` to `break_glass.requested` + `break_glass.access_granted`
   - Export to CSV

2. **For each event, confirm 4 things:**
   - [ ] Justification text is specific (not just "emergency" or "needed access")
   - [ ] User's department fits plausible clinical need
   - [ ] Access duration matches the stated need
   - [ ] Subsequent `break_glass.access_revoked` or auto-expiration fired

3. **Any event that fails any of the 4 checks** → escalate to department head.

4. **Aggregate patterns** to watch for:
   - Same user > 5 events in a month
   - Same participant accessed by > 3 different break-glass events in a month
   - Events outside business hours in a dept that doesn't do after-hours (finance, activities)
   - Events on disenrolled / deceased participants (should be nearly zero)

5. **Sign the review.** Create a monthly note in `/it-admin/security` describing:
   - Total event count
   - How many passed / escalated
   - Any pattern findings
   - Date + reviewer name

## Who has authority to use break-glass

- Primary care, nursing, pharmacy, social work on-call rotations
- IT admin for emergency technical access
- Super-admin for any break-glass override

Anyone else using break-glass without prior conversation with their department head should trigger an immediate follow-up, not just a monthly review.

## Related audit log actions

Break-glass touches multiple audit log entries per event:
- `break_glass.requested` — user asked for access
- `break_glass.access_granted` — system approved
- `break_glass.chart_accessed` — first chart view under the grant
- `break_glass.access_revoked` — user ended access early
- `break_glass.access_expired` — automatic expiration

Together these form a complete timeline of what the user actually did during the window.

## Retention

Audit logs are immutable + append-only (PostgreSQL CHECK constraints prevent UPDATE / DELETE). Retention is 10 years per CMS PACE audit protocol. Do not delete.
