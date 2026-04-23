# Privacy + Security Policy Surfaces

The EMR already exposes three public policy surfaces (Phase 5):
- `/policy/info-blocking` — 21st Century Cures Act §4004 / ONC HTI-1
- `/policy/notice-of-privacy-practices` — 45 CFR §164.520 NPP
- `/policy/acceptable-use`

Each has a footer link across the application. Below is the policy content + update workflow.

## Info-blocking policy (21st Century Cures Act)

**What it says (public-facing):**
- NostosEMR makes electronic health information accessible via FHIR R4 API
- No practice prevents, materially discourages, or otherwise inhibits access, exchange, or use of EHI
- Exceptions (8 defined in ONC HTI-1) are documented with reasons + responsible role
- Participants + designated representatives can request an EHI export at `/participants/{id}/ehi-export`

**Implementation:**
- FHIR R4 read endpoints — all 13 resources, SMART OAuth — are live (Phase 11)
- Bulk export via `$export` — Phase 15.1
- EHI export self-service — Phase 5
- Any vendor charging a fee for connection must be documented under the "fees" exception with audit trail

## Notice of Privacy Practices (NPP)

**Required under HIPAA §164.520.** Covers:
- How the tenant may use / disclose PHI
- Participant's rights (access, amend, restrict, confidential comm, accounting of disclosures, paper copy of NPP)
- Complaints — how to file internally + to HHS OCR
- Tenant's contact for privacy questions

**Tenant-specific fields** (injected on the public page per tenant):
- Privacy officer name + phone + email
- Mailing address
- Effective date

## Acceptable Use

**Internal users:**
- Only access PHI you need for your job (minimum necessary)
- No shared accounts; no shared OTPs
- Screen lock when stepping away
- No PHI on personal devices unless approved (BYOD policy)
- No USB removable media with PHI unless approved (encrypted + audited)
- Break-glass: emergency-only; justification required; monthly review
- Reporting suspected breach: within 1 business day to privacy officer

**External (API / FHIR clients):**
- Must hold a signed BAA
- Respect scope (no reading data you don't need)
- Retain data per your own BAA terms
- Destroy data at contract end

## Update cadence

- Annual review of all three policies
- Ad-hoc updates when regulations change (e.g., new ONC HTI rule, HIPAA update)
- Each update: bump the effective date + notify tenant operations lead

## Where the files live

`resources/views/policy/*.blade.php` — edit + commit. Versioning via git is the record.
