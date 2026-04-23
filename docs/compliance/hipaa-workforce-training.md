# HIPAA Workforce Training — Program Outline

**Regulatory driver:** 45 CFR §164.530(b)(1) — "A covered entity must train all members of its workforce on the policies and procedures with respect to protected health information required by this subpart, as necessary and appropriate for the members of the workforce to carry out their functions."

**Scope:** Every workforce member with access to NostosEMR — employees, contractors, students, volunteers.

## Curriculum (one-time initial + annual refresher)

1. **What PHI is** — 18 HIPAA identifiers; electronic vs. paper vs. verbal
2. **Minimum necessary rule** — access what you need for the job, nothing more
3. **Your responsibilities** — reporting suspected breaches, not sharing OTPs, locking screens
4. **NostosEMR-specific controls:**
   - OTP login, no shared accounts
   - Break-glass access is audited (monthly review)
   - All reads are logged — "view only" isn't invisible
   - Screens auto-lock after 15 min idle
   - USB + printer policy (per tenant IT policy)
5. **Incident reporting** — how to report a suspected breach within 1 business day
6. **Sanctions** — policy violation consequences (tenant HR policy)

## Delivery

1. **Initial training** before the user is granted production access
2. **Annual refresher** within 90 days of hire anniversary
3. **Ad-hoc training** on material policy changes

## In-app attestation (future Phase P-2 build)

The EMR should capture training attestations in-app:
- New `emr_hipaa_training_attestations` table: `user_id, tenant_id, completed_at, expires_at, training_version, attested_by_user`
- Block login with a soft gate ("complete training to continue") when attestation is expired
- IT admin dashboard widget: count of users with expired attestation

This is NOT yet built. Operationally today: tenant HR tracks attestations outside the EMR; IT admin + QA should audit for gaps quarterly.

## Documentation retention

Attestation records: 6 years per HIPAA. Store in the tenant's HR system + reference via `/it-admin/security` notes.
