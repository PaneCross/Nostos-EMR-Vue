# Data Correction Runbook

**Audience:** IT Admin + QA Compliance.

## First principle: never delete, always correct

HIPAA + 42 CFR §460 require that the medical record show "what actually happened and when we knew it." You cannot edit a past entry out of the record. You can:

1. **Mark an entry as superseded** — the original stays, a new entry replaces it.
2. **Add a correction note** — visible amendment.
3. **Soft-delete** (rarely, for clearly-wrong data like a typo in a participant's name that hasn't been dispatched to any external system).

## Common correction scenarios

### A. Wrong participant demographics
**Safe to correct inline.** Edit via `/participants/{id}` (requires enrollment or super_admin). The previous values are captured in `shared_audit_logs` `old_values` / `new_values`.

### B. Wrong medication dose on an active prescription
**Do NOT edit the existing row.** That medication may already have eMAR doses administered at the old strength. Instead:
1. Discontinue the existing med with a clinical reason
2. Prescribe the correct dose as a new med
3. Add a note on both explaining the correction

### C. Wrong clinical note content
If the note is **unsigned**: edit freely.
If the note is **signed**: create an addendum (a new note linked to the original with `addendum_to_id`). Signed notes are immutable.

### D. Duplicate participant record
**Carefully.** Two workflows:
- If no clinical data attached to either: soft-delete the duplicate via IT admin
- If clinical data on both: this is a merge operation. Open a ticket; there is no self-service merge today (it's on the Phase 15+ backlog).

### E. Wrong grievance / SDR / appeal
Use the existing state-machine "withdrawn" / "cancelled" status. Do NOT delete. The audit trail is the point.

### F. Over-billing to CMS / state Medicaid
Correction flow is regulatory:
1. Reverse the claim through the clearinghouse (or paper, if null-gateway active)
2. Record a reversal on the original EdiBatch with a clinical note
3. File a corrected 837P via the normal flow
4. The denial workflow tracks the reversal round-trip

## Data deletion approved by CMS / ONC

Only three cases where you can actually remove data:
1. **Mistaken identity** — the record belongs to the wrong person and no clinical action was taken
2. **Participant request under 45 CFR 164.526** — with CMS guidance
3. **Data subject to a legal hold** that was released

Always consult the Privacy Officer and document the decision in `/it-admin/security` before performing any hard delete.

## Audit trail

Every correction writes at least one `shared_audit_logs` entry with `action = {entity}.updated` and the before/after values. Retention is 10 years.
