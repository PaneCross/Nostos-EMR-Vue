# New User Runbook

**Audience:** IT Admin.

## 1. Collect the info

Before creating the account:
- Full legal name
- Primary work email (must be unique in the tenant)
- **Department** — one of: primary_care, nursing, therapies, social_work, behavioral_health, dietary, activities, home_care, pharmacy, enrollment, finance, qa_compliance, it_admin, executive, super_admin
- **Role** — `standard` (most users), `admin` (department head / power user), `super_admin` (tenant-wide; use sparingly)
- **Designations** (optional sub-roles) — e.g. `medical_director`, `pcp`, `compliance_officer`, `social_worker_lead`. These drive targeted alerts + approval workflows.

## 2. Create the user

From `/it-admin/users` (or tinker):

```php
$user = \App\Models\User::create([
    'tenant_id'  => $tenantId,
    'first_name' => 'Jane',
    'last_name'  => 'Doe',
    'email'      => 'jane.doe@tenant.org',
    'department' => 'primary_care',
    'role'       => 'standard',
    'is_active'  => true,
]);
if ($designations) {
    $user->update(['designations' => $designations]);
}
```

## 3. First login

User visits `/login`, enters email, receives OTP via email (6-digit code, 15-min validity). No password.

On first successful login, they'll be prompted to pick a theme (light/dark) and verify their display name. Done.

## 4. Designations

Designations are stored as a JSON array on the user. They drive **who gets paged** for specific events (e.g., `medical_director` gets the critical NF-LOC overdue alert; `compliance_officer` gets every overdue grievance).

Current designation slugs:
- `medical_director`
- `pcp`
- `compliance_officer`
- `social_worker_lead`
- `nursing_director`
- `pharmacy_director`
- `qapi_chair`

Add new ones sparingly — each one needs corresponding logic in `AlertService::targetsForDesignation`.

## 5. Deactivate (not delete)

Never delete user records. Set `is_active = false`; audit log references to the user stay intact.

```php
$user->update(['is_active' => false]);
```

The user can no longer log in, but historical chart signatures and audit entries remain valid.
