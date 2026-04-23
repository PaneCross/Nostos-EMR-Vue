# Tenant Onboarding Runbook

**Audience:** IT Admin / implementation lead.
**Frequency:** Once per new PACE organization going live.
**Estimated time:** 2-4 hours hands-on + 1 week of parallel config.

---

## Prerequisites

- [ ] Signed service agreement + Business Associate Agreement (BAA) on file
- [ ] PACE organization's CMS contract ID + H-number confirmed
- [ ] List of operating sites (name, address, mrn_prefix)
- [ ] List of Day 1 users (name, email, department, role, designations)
- [ ] State of operation confirmed (drives Medicaid config + PDMP requirements)
- [ ] If going live with ePrescribing: DrFirst contract signed (see `project_eprescribing_vendor_design.md`)
- [ ] If going live with real claims: clearinghouse agreement (Availity / Change Healthcare / Office Ally)

---

## Step 1: Provision the tenant

From the IT admin shell (WSL2, nostosemr-vue project):

```bash
./vendor/bin/sail artisan tinker
```

```php
$tenant = \App\Models\Tenant::create([
    'name'         => 'ACME PACE Services',
    'contract_id'  => 'H1234',
    'h_number'     => 'H1234',
    'is_active'    => true,
]);
echo "Tenant ID: {$tenant->id}\n";
```

Write down the tenant ID — you'll need it for the rest of the steps.

## Step 2: Seed baseline roles + permissions

```bash
./vendor/bin/sail artisan db:seed --class=PermissionSeeder
```

This is idempotent and safe to re-run. It ensures each department role has its default permission set.

## Step 3: Create sites

Each PACE center / day center is a site. MRN prefix is 3-4 chars.

```php
foreach ([['Main Center','MAIN'],['North Annex','NRTH']] as [$name, $prefix]) {
    \App\Models\Site::create([
        'tenant_id'  => $tenant->id,
        'name'       => $name,
        'mrn_prefix' => $prefix,
        'is_active'  => true,
    ]);
}
```

## Step 4: Create Day 1 users

For each Day 1 staff member:

```php
$user = \App\Models\User::create([
    'tenant_id'  => $tenant->id,
    'first_name' => 'Jane',
    'last_name'  => 'Doe',
    'email'      => 'jane.doe@acmepace.org',
    'department' => 'primary_care',   // or: nursing, therapies, social_work, pharmacy, dietary, activities, home_care, enrollment, finance, qa_compliance, it_admin, executive, super_admin
    'role'       => 'admin',           // or: standard, super_admin
    'is_active'  => true,
]);
```

OTP login is automatic — users receive a magic-link email on their first `/login` attempt.

**Safety:** always create at least one `super_admin` user first. That account is the break-glass owner for the tenant.

## Step 5: Formulary import (optional Day 1)

If the tenant has an existing formulary to migrate:

1. Have tenant export a CSV with columns:
   `drug_name,generic_name,rxnorm_code,tier,prior_authorization_required,quantity_limit,step_therapy_required,notes`
2. Log into NostosEMR as an IT admin
3. Go to `/data-imports`, pick "medications" (or extend `DataImportService` for formulary)
4. Upload → Review preview → Commit

(At the time of writing, the canonical `DataImportService` covers participants / problems / allergies / medications. Formulary migration is a one-time API push until a specific formulary mapper is added.)

## Step 6: State Medicaid configuration

Go to `/it-admin/state-config`. Create one entry per state the tenant operates in:
- state_code (2 letters)
- state_name
- submission_format (`837P` is default)
- companion_guide_notes (free-text: state-specific quirks)
- days_to_submit (default 180)

## Step 7: Clearinghouse configuration (if contracted)

Go to `/it-admin/clearinghouse-config`. Default is `null_gateway` (stages 837P for manual portal upload). When a vendor contract is signed:

1. Get credentials from the vendor (submitter ID, API key or SFTP key, endpoint URL)
2. Create a new config with `adapter` set to the vendor
3. Store credentials in the encrypted `credentials_json` field
4. Activate the row (any other active row auto-deactivates inside a transaction)
5. Click "Health check" per config to confirm — vendor stubs return `false` until the adapter body is implemented

## Step 8: DNS + SSL

Not code — infrastructure. Terminate TLS at the load balancer; point `emr.{tenant}.nostos.tech` (or vanity domain) at the app server. Verify the cert covers all subdomains used (`emr.`, `fhir.`).

## Step 9: Go-live checklist

- [ ] Super-admin account created and confirmed
- [ ] Baseline roles + permissions seeded
- [ ] At least one site created
- [ ] State Medicaid config for each state of operation
- [ ] Clearinghouse config (or accept null gateway)
- [ ] DNS + SSL verified
- [ ] First participant record created successfully (either manually or via `DataImport`)
- [ ] OTP email delivery confirmed (check Mailpit or production mail provider)
- [ ] Break-glass access policy reviewed with staff (see `admin-runbooks/break-glass-review.md`)
- [ ] BAA signed copy stored in `/it-admin/security` (BAA tab)

---

## Post-launch — first 30 days

- Monitor audit log daily for unusual access patterns
- Monthly break-glass event review
- First quarterly Level I/II export runs on day 90 — verify data is flowing
- Confirm all staff have completed HIPAA attestation (if Phase P-2 enabled)
