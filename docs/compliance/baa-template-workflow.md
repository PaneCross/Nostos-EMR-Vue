# Business Associate Agreement (BAA) — Template + Workflow

**Regulatory driver:** 45 CFR §164.308(b)(1) — covered entities must have written assurances from business associates handling PHI.

## When a BAA is required

A BAA is needed whenever a third party either:
- **Creates, receives, maintains, or transmits PHI** on the tenant's behalf
- **Provides services involving PHI** (legal, accounting, audit, consulting, storage)

Common BAA-required vendors in a PACE setup:
- EMR vendor (us — NostosEMR / Nostos Technologies LLC)
- Clearinghouse (Availity / Change Healthcare / Office Ally)
- ePrescribing partner (DrFirst)
- Cloud hosting (AWS / Azure / GCP)
- Mail provider (SES / SendGrid if routing OTP or PHI emails)
- Backup provider
- Any consultant auditing charts

Not BAA-required:
- General office software (Microsoft 365 with HIPAA-aligned commercial license IS covered by MS's BAA; separate signing not always required)
- Transit gateways that never see PHI payload

## Template sections (tenant uses their legal counsel's version)

A BAA must cover:
1. **Permitted uses + disclosures** of PHI by the business associate
2. **Prohibition on other uses** + duty to report unauthorized uses
3. **Safeguards** — administrative, physical, technical
4. **Subcontractor pass-through** — any subcontractor must sign an equivalent BAA
5. **Individual rights** — access, amendment, accounting of disclosures
6. **Breach notification** within 60 days of discovery (some tenants require shorter)
7. **Term + termination** including PHI return/destruction at contract end
8. **Indemnification** (tenant's preference)
9. **Regulatory audit cooperation**

## In-app tracking

`/it-admin/security` Security tab has a **BAA table**:
- Vendor name + contact
- BAA signed date + effective date
- Expiration / renewal date
- Document attachment (signed PDF)
- Status (active / expired / terminated)

IT admin creates + updates these rows. 30-day pre-expiration alert surfaces to the IT admin dashboard.

## Annual BAA review

Once a year (typically calendar Q4 for the following year):
- [ ] Pull all active BAAs from `/it-admin/security`
- [ ] Verify each is still needed (the vendor relationship is still active)
- [ ] Flag any expiring within 90 days for renewal negotiation
- [ ] Remove / terminate any that are no longer active
- [ ] File an annual BAA-review summary note

## What happens if a vendor changes ownership

Most BAAs have a "successor + assigns" clause — usually fine. But if the acquirer has a materially different security posture, re-sign. Ask your legal counsel.
