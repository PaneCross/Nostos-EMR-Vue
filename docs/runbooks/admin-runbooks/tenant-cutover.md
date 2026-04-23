# Tenant Cutover Runbook

**Audience:** Implementation lead + IT Admin.
**Frequency:** Once per tenant going live from an incumbent EMR.

Cutover = the moment the tenant stops using their old EMR and starts using NostosEMR. The goal is to go live without losing a single active participant or a single day of clinical continuity.

## T-minus 30 days

- [ ] Confirm all Day 1 users created (see `new-user.md`)
- [ ] State Medicaid + clearinghouse config in place or null-gateway acknowledged
- [ ] Data migration from incumbent plan locked (see below)
- [ ] Training sessions scheduled for each department

## T-minus 14 days — data migration dry run

Use `/data-imports` to do a full dry run:

1. Tenant exports from their old EMR:
   - `participants.csv` (demographics + enrollment status)
   - `problems.csv` (ICD-10 + optional SNOMED)
   - `allergies.csv`
   - `medications.csv` (active only)
2. Upload each in `/data-imports`; review preview + errors
3. **Do NOT commit yet** — fix mapping issues, re-export, re-upload until error count is zero
4. On cutover day, commit in order: participants → problems → allergies → medications

**Order matters:** child entities reference participants by MRN; problems/meds can't commit before their participants.

## T-minus 7 days — mock go-live

1. Commit dry-run data into a STAGING tenant
2. Have each department walk through their Day 1 workflow
3. Note any feature gaps (likely already captured in `backlog_ui_audits.md`)
4. Fix or document workarounds

## Cutover day

**Morning (before clinic opens):**
1. Tenant does their final export from the old EMR
2. IT imports participants / problems / allergies / medications into the production tenant
3. Verify participant count matches the source
4. Verify representative sample of 5 charts look right
5. Have super-admin log in and sanity-check dashboards

**Clinic opens:**
6. Staff logs in; OTP emails flow
7. First real encounter documented
8. First real appointment scheduled
9. First real order placed

**End of day cutover checkpoint:**
- [ ] No staff reported being locked out
- [ ] No major data-integrity complaint
- [ ] Audit log reflects expected activity volume
- [ ] Pager rotation knows how to reach the implementation team overnight

## Post-cutover — first week

- Daily stand-up with tenant operations lead
- Watch for CDS alert storms (Phase 15.6 rules — fall risk, sepsis, anticoag+NSAID). Expect an initial spike as legacy data flows through — investigate each.
- Confirm capitation billing generates cleanly for the first submission cycle
- File first grievance, first SDR, first encounter, first Level I/II quarterly

## What to do if cutover fails

**Failure modes + responses:**
- **Mass login failure** → check mail delivery; emergency OTP-less admin access via tinker if needed
- **Data integrity issue** → roll back the specific `DataImport` row (mark `cancelled`; add a new one with corrected data); participants already imported stay — only re-import the specific corrected subset
- **Dashboard doesn't load** → check browser console; Reverb WebSocket may be misconfigured; Vite manifest issues after deploy require `npm run build`
- **Provider signatures not working** → check user designations + department; super-admin impersonation to diagnose

**Nuclear option:** roll the tenant back to their old EMR for the day, fix the issue, cut over again the next business day. Participants are not billed for the interruption.
