# CMS PACE Audit Dry Run

**Audience:** QA Compliance + Executive.
**Frequency:** Annually, typically Q4 for the following year's CMS audit cycle.

## Why dry run

CMS PACE organizations are subject to the **PACE Audit Protocol** — a standardized document-pull + universe-review exercise. CMS gives 30-60 days notice; the organization must produce the universes within that window, at which point CMS reviews for compliance.

A dry run in-house before the real audit:
1. Proves every universe actually generates without errors
2. Catches data gaps before CMS does
3. Gives staff practice with the response cadence
4. Informs budget for corrective actions

## Universes NostosEMR provides

All at `/compliance/*`:
- `/compliance/nf-loc-status` — NF Level of Care certifications
- `/compliance/denial-notices` — service denial notices + appeals
- `/compliance/appeals` — active + resolved appeals
- `/compliance/sdr-sla` — SDR 72h/24h clock performance
- `/compliance/personnel-credentials` — §460.64-71 staff credentialing
- `/compliance/level-ii-reporting` — quarterly quality indicators

## Dry run procedure

1. **Pretend CMS notice arrives today.** Set a 30-day internal deadline.
2. **Pull each universe.** Verify:
   - It generates without error
   - Row counts look plausible
   - Date ranges hit edge cases (prior fiscal year + current fiscal year)
   - No PHI leaks to the wrong tenant
3. **Spot-check 20 records** per universe — do the rows match what the underlying EMR data says?
4. **Generate Level I/II quarterly report** for a recent completed quarter (`/compliance/level-ii-reporting`). Verify denominators + numerators.
5. **Pull a representative SDR history** — urgent + standard examples through their full lifecycle.
6. **Pull grievance aging** — show examples at green / yellow / red / overdue bands.
7. **Check break-glass review documentation** for the last 12 months.
8. **Check HIPAA training attestation** coverage (if Phase P-2 in-app gates are live).
9. **Pen test + DR test evidence** — prove you did them annually.
10. **BAA list** — current, up-to-date.

## Findings format

For each deficiency:
- What universe / control was deficient
- What the gap is
- Who owns remediation
- Target deadline
- Evidence of remediation once done

## Fictional example finding

> **Universe:** `/compliance/sdr-sla`
> **Gap:** 3 of last 50 expedited SDRs did not have a documented 24-hour decision. Missing timestamps in `Sdr.decided_at`.
> **Remediation:** Policy reminder to staff + mandatory field validation at save time. Deadline: 30 days.
> **Evidence:** Policy memo signed by Medical Director; pre-save validation test added (`tests/Feature/SdrDecisionTimestampTest.php`).

## After the dry run

Write a summary report. File under `/it-admin/security` → "Annual CMS audit dry run" entry. Present findings to governing board at next meeting. Prioritize remediation for the following quarter.
