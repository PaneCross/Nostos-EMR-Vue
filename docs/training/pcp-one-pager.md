# Primary Care Provider — One-Pager

Welcome to NostosEMR. This is the 5-minute orientation for primary-care providers (MD / DO / NP / PA).

## Logging in
- Visit `/login`; enter your work email; receive a 6-digit OTP in email (valid 15 min)
- No password. If you don't get the OTP, check spam + ping IT admin.

## Your daily workflow surfaces

1. **Dashboard `/dashboard/primary-care`** — your patient queue today: appointments, overdue reviews, new orders, critical alerts
2. **Participant chart** — click any participant name → 27 tabs. The ones you'll live in:
   - **Overview** — facesheet
   - **Problems** — ICD-10 + SNOMED coded problem list
   - **Medications** — all active meds, `+ Prescribe` button (flags interactions at entry)
   - **Allergies** — drug-allergy list; red banner if severe
   - **Orders** — labs, imaging, therapy referrals, DME, consults
   - **Assessments** — PHQ-9, Mini-Cog, Morse Fall Scale, Katz ADL (scored automatically)
   - **Clinical Notes** — write + sign progress notes
   - **Vitals** — trended; CDS flags abnormal
3. **Cmd+K / Ctrl+K global search** — jump to any participant, referral, appointment, grievance, order, SDR

## Prescribing
- Hit **Medications → Prescribe**. As you type the drug name, the drug-drug interaction preview runs against the participant's current meds. Major / contraindicated pairs prompt a confirm step.
- Formulary check is inline: tier badge + PA/QL/ST restriction chips.
- If the drug is off-formulary or needs PA, use **Request coverage determination** — fires off to pharmacy for workup.

## Clinical decision support (CDS)
Three rules trigger alerts automatically:
- **Fall risk** — Morse score ≥45 triggers fall-prevention alert
- **Sepsis screen** — qSOFA+fever combination flags for urgent evaluation
- **Anticoag + NSAID** — concurrent active meds warn you to add GI prophylaxis or rethink

Alerts show in your bell; click to ack.

## Signing notes
- Unsigned notes: editable
- Signed notes: immutable. Use "+ Addendum" for corrections.
- Co-sign a resident / APP note via **Sign on behalf of** (if you're the attending).

## Orders
- Orders route automatically by type:
  - Labs / imaging → primary care worklist
  - Therapy PT / OT / ST → therapies worklist
  - Med changes → pharmacy
  - Social work referrals → social work
  - DME → home care
  - Hospice referrals → social work (specialty workflow)
- Stat orders ping the on-call immediately.

## After hours / break-glass
If you need to access a participant outside your normal team, hit **Request break-glass access** on the chart. Provide a specific clinical justification. Access is time-limited (default 4h). Every event is reviewed monthly by QA.

## When something's wrong
- Dashboard blank / doesn't load → refresh once; if still broken, IT admin
- Rx save fails → screenshot + email IT; do NOT retry blindly
- CDS alert fires on something clearly wrong (e.g., false sepsis flag) → still ack it; add a clinical note explaining; the dedup window prevents spam

## Regulatory reminders
- PACE participant ≠ Medicare FFS patient. Capitation applies.
- 42 CFR §460 says the IDT (not the PCP alone) owns the care plan. Your sign-off is necessary but not sufficient.
- Level I/II quarterly reporting pulls your problem/med/fall data. Keep it coded.
