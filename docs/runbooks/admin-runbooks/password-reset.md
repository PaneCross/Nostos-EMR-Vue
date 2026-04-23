# Password Reset / OTP Troubleshooting

**Audience:** IT Admin.

NostosEMR uses email-based OTP — there is no password. "I can't log in" usually means one of:

## Scenarios + fixes

### 1. User says "I'm not receiving the OTP email"

1. Verify the user's email on file matches what they're typing (case-sensitive on some providers).
2. Have the user check spam / quarantine — OTP email from the tenant's sending domain.
3. Check Mailpit (dev env: http://localhost:8026) or your production email provider's delivery log.
4. Go to `/it-admin/users/{id}` and click "Resend invite" — this purges any pending OTP and issues a fresh one.
5. If emails aren't flowing from the tenant at all, the mail provider config is wrong. Check `.env` `MAIL_*` settings.

### 2. User has old email / email changed

Update the user's email via `/it-admin/users/{id}`. Next login will send OTP to the new address. Old pending OTPs are auto-purged nightly (`otp:purge-expired` scheduled command).

### 3. User is locked out due to too many failed attempts

Check `failed_login_attempts` + `locked_until` on `shared_users`. Clear via:

```php
$user->update(['failed_login_attempts' => 0, 'locked_until' => null]);
```

### 4. User is deactivated

`is_active = false` blocks login. Re-activate via `/it-admin/users/{id}` if legitimate.

### 5. Wrong tenant

If the user belongs to a different tenant (rare but possible in multi-tenant deployments), they should log in via that tenant's URL.

## Break-glass access for IT

If IT needs to log in AS another user (e.g. to reproduce a bug they're reporting):

- **Do NOT share OTPs.** Sharing OTP defeats audit.
- Use the super-admin impersonation flow at `/it-admin/users/{id}/impersonate` (if enabled). All impersonated-session events are tagged with both user IDs in `shared_audit_logs`.
- Impersonation should be reviewed as part of the monthly break-glass review.
