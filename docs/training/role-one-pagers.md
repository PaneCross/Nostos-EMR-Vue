# Role one-pagers — consolidated

One page each for the remaining Day-1 roles. (PCP + RN have their own full files.)

---

## Social Worker

**Daily surfaces:** `/dashboard/social-work`, Grievances tab, Incidents tab, Appeals tab, Assessments tab (psych-social), care plan goals assigned to SW.

**Critical workflows:**
- Log grievances within 24h of receipt (standard 30-day clock; urgent 72h)
- File appeals for service denials per 42 CFR §460.122 (standard 30d / expedited 72h)
- Hospice / end-of-life referrals route here
- SDR (service determination request) triage — your clock is 72h standard / 24h expedited

**Don't miss:** aging color on grievance list — green/yellow/red/pulsing-red = overdue. Day-25 alert fires before overdue hits.

---

## Scheduler / Enrollment

**Daily surfaces:** `/enrollment`, Referrals (Kanban), Appointments (`/schedule`), Site transfers.

**Critical workflows:**
- Referral intake → potential enrollee → enrolled participant (9-status state machine)
- Schedule intake visits, facility tours
- Site transfers with 30-day advance window
- Day-center attendance roster

**Don't miss:** participant vs. potential enrollee terminology. Real participants are enrolled; anyone pre-enrollment is a potential enrollee. This matters for audits + billing.

---

## QAPI / Quality Improvement

**Daily surfaces:** `/dashboard/qa-compliance`, QAPI annual evaluation, incidents, grievances (escalated), Level I/II quarterly reporting, compliance audit universes.

**Critical workflows:**
- Incident reporting (72h CMS notification clock)
- Significant change events (14-day IDT review clock)
- Level I/II quarterly export (mortality, falls w/injury, pressure injuries, immunizations, etc.)
- Personnel credentialing audit pulls
- NF-LOC recert tracking (annual)

**Don't miss:** compliance audit universe pages at `/compliance/*` — surveyor-ready JSON. QAPI committee meetings recorded under `/committees`.

---

## Billing / Finance

**Daily surfaces:** `/dashboard/finance`, Encounters tab, Claims, Remittance (835), Denials, CMS reconciliation (MMR/TRR), Spend-down dashboard widget.

**Critical workflows:**
- Daily encounter log review — which encounters are ready for 837P batch
- Build + transmit 837P (or stage for manual upload under null gateway)
- Ingest 835 ERA files (per-tenant automatic under real adapter; manual upload under null gateway)
- Denial triage + appeal filing; watch the 120-day appeal-deadline alert
- Medicaid spend-down: participants with monthly share-of-cost must meet obligation before capitation can bill
- MMR/TRR reconciliation monthly

**Don't miss:** clearinghouse config at `/it-admin/clearinghouse-config`. Current default is `null_gateway` = manual upload. Real transmission requires a signed vendor contract.

---

## Pharmacy

**Daily surfaces:** `/dashboard/pharmacy`, eMAR review, drug-interaction alerts, `/formulary`, coverage determinations queue.

**Critical workflows:**
- Review + acknowledge drug-interaction alerts (dedup window is 24h)
- Approve / deny formulary coverage determinations (PA, tier exception, QL override, step-therapy override)
- Maintain formulary: add / update entries, set tiers, PA flags
- PDMP query before controlled-substance Rx (post-Phase-10 vendor activation)

**Don't miss:** formulary check runs inline at the prescriber's screen. Your maintenance of the catalog directly affects what PCPs see.

---

## IT Admin

**Daily surfaces:** `/it-admin/*` everything, audit log, security + BAA, state Medicaid + clearinghouse configs.

**Critical workflows:**
- User provisioning + deactivation
- OTP troubleshooting
- Monthly break-glass review
- Security-risk assessment (SRA) updates
- Clearinghouse adapter switching when vendor contracts activate
- Data migration wizard (`/data-imports`) for tenant onboarding

**Don't miss:** you're the only role with `it_admin` permissions. Share admin access conservatively; audit catches everything but preventing abuse beats investigating it.
