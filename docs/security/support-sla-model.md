# Support + SLA Model

**Draft for initial customer contracts.** Adjust to tenant size + contract value.

## Support tiers

### Standard (included)
- Business-hours support 9-5 local, Mon-Fri
- Response time targets:
  - Severity 1 (production down for multiple users): 2 hours
  - Severity 2 (feature broken, workaround available): 1 business day
  - Severity 3 (minor bug, cosmetic issue): 3 business days
  - Severity 4 (enhancement request): backlog; response 5 business days
- Email + portal support
- Knowledge base access
- Quarterly business review

### Premium (additional)
- 24x7 on-call for Severity 1
- 1-hour Severity 1 response
- Dedicated customer success manager
- Phone support
- Monthly business review
- Custom report + dashboard work (bounded hours)

### Enterprise (additional)
- Named engineer / architect access
- Quarterly code-review + security-review sessions
- Priority for feature requests
- SSO / SAML integration support (Phase 15.2 vendor integrations)
- Custom SLA terms

## Severity definitions

- **Sev 1:** EMR is unusable for a majority of the tenant's users. Examples: login broken, chart won't load, data corruption confirmed.
- **Sev 2:** A specific feature is broken but users can work around it. Examples: PDF generation fails for care plans, one dashboard widget doesn't load, OTP takes >5 min.
- **Sev 3:** Minor bug affecting a small set of users. Examples: date displays wrong in one place, form validation too strict.
- **Sev 4:** Feature request or enhancement that isn't regressing existing functionality.

## Escalation path

1. User opens ticket via in-app help or dedicated email
2. Support engineer triages, assigns severity
3. If Sev 1: immediate page to on-call
4. If not resolved in SLA: escalate to engineering manager
5. If SLA violation: escalate to VP of engineering + account manager

## SLA credits

On SLA breach, credits apply to the next invoice:
- Monthly uptime < 99.5% → 10% credit
- Monthly uptime < 99.0% → 25% credit
- Monthly uptime < 95.0% → 50% credit
- Single outage > 4 hours → 10% credit

Uptime excludes scheduled maintenance (announced ≥ 7 days ahead).

## Scheduled maintenance

- Monthly: third Sunday 02:00-04:00 local; ≤ 30 min expected downtime
- Quarterly: deeper maintenance window 01:00-06:00; ≤ 2 hours
- Emergency patches: 24-hour notice minimum when possible

## Customer success cadence

- Weekly: operational metrics dashboard (auto-generated)
- Monthly (Premium+): business review
- Quarterly (all tiers): business review with exec + roadmap preview
- Annually: renewal conversation + customer advisory input

## Out of scope

- Customer's endpoint devices (laptops, tablets, printers)
- Customer's internal IT (wifi, corporate VPN)
- Third-party vendor outages (DrFirst, clearinghouse, etc.) — we troubleshoot with the vendor but can't fix their downtime
- Customer training beyond the initial onboarding session (Premium includes one refresher/quarter)
