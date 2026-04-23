# Registered Nurse — One-Pager

## Your daily surfaces
- **Dashboard `/dashboard/nursing`** — today's eMAR, wound care queue, critical alerts, unsigned handoffs
- **eMAR (Medications tab)** — scheduled doses for the day; late-dose detection runs every 30 min and flags overdue
- **Vitals tab** — record BP / pulse / temp / respiration / pulse-ox / pain; CDS rules watch for sepsis + fall risk
- **Wound care (Wounds tab)** — staged pressure injuries, turn schedule, photos
- **Care plan goals** — check off interventions assigned to nursing
- **Mobile ADL** `/home-care/mobile-adl` — tablet-friendly home-visit documentation

## eMAR rules
- Mark a dose "administered" only if you actually gave it
- Refused → mark refused + reason
- Late (> 30 min past scheduled) → auto-flags with warning alert; still document truthfully
- PRN doses don't pre-schedule; they show when you click "Administer PRN"

## Critical alerts you'll see
- `allergy_critical` — participant has severe or life-threatening allergy; read before acting
- `sdr_warning_24h` / `sdr_overdue` — service determination request clock running out
- `cds_anticoag_nsaid` — participant on anticoagulant + NSAID; GI prophylaxis review
- `wound_stage_2_plus` — pressure injury stage 2 or higher; care plan update required

## Handoff protocol
- Start of shift: pull today's handoff report from the dashboard
- End of shift: sign off your handoff notes; any critical observations belong in the clinical note, not just the handoff

## When a provider isn't available
- Non-urgent clinical question: message via chat widget
- Urgent + no PCP on call: use **Request break-glass access** to page the on-call provider via the alert system
- Stat order / emergency: call 911 first; documentation after

## Documentation hygiene
- Every vitals entry, wound assessment, and eMAR action creates an immutable audit trail
- Corrections: add addendum, don't edit — see `data-correction.md`
