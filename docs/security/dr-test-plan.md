# Disaster Recovery Test Plan

**Audience:** IT Admin + Infrastructure lead.
**Frequency:** Annually at minimum. Quarterly once a tenant is at scale.
**Regulatory driver:** HIPAA §164.308(a)(7) — contingency plan requirement.

## Recovery targets

- **RPO (Recovery Point Objective):** ≤ 1 hour of data loss
- **RTO (Recovery Time Objective):** ≤ 4 hours from declared disaster to production-restored
- **MTTR (Mean Time To Recovery):** ≤ 2 hours for normal production incidents

## Backup policy

- **Database (PostgreSQL):**
  - Continuous WAL archiving to a different region (hourly ship)
  - Nightly full dump at 02:00 local
  - Weekly full dump retained 13 weeks
  - Monthly full dump retained 7 years (HIPAA retention + CMS PACE 10-year requires longer for audit-log tables; see `Audit log retention` below)
- **Filesystem storage (storage/app/):**
  - Nightly incremental to a different region
  - Contains EHI exports, PDFs, CSV imports, EDI batches
- **Configuration / `.env`:**
  - Stored in a secrets manager (AWS Secrets Manager / similar)
  - Not in backups (would expose credentials)

## Audit log retention (special)

`shared_audit_logs` and `emr_*_events` tables must be retained ≥ **10 years** per CMS PACE Audit Protocol. Backup cadence: monthly archive to cold storage.

## DR test procedure (annual)

**Goal:** prove we can restore a full tenant from backup into a non-production environment in ≤ 4 hours.

1. **T+0h** — Declare test; notify team
2. **T+0.25h** — Spin up isolated DR environment (blank Postgres + Laravel app)
3. **T+0.5h** — Restore latest Postgres dump; apply WAL since dump timestamp
4. **T+1.5h** — Restore filesystem backup
5. **T+2h** — Run `php artisan config:cache` + `php artisan migrate --pretend` (should be zero pending migrations)
6. **T+2.5h** — Create an admin account via tinker; log in; verify 10 representative participants match expected data
7. **T+3h** — Run `./vendor/bin/sail test` — full suite should pass
8. **T+4h** — Test complete. Record: RPO actual, RTO actual, any data gaps, any process gaps
9. **T+4h-7d** — Write DR test report; file under `/it-admin/security` "Annual DR test" entry

## Failure modes + mitigations

| Scenario | Mitigation |
|---|---|
| Primary DB server loses disks | WAL shipping catches up; promote read replica |
| AZ outage | Cross-region backup + cold standby |
| Ransomware on primary filesystem | Read-only snapshots in separate account |
| Accidental `DROP TABLE` | PITR to pre-drop timestamp; WAL replay |
| Full region outage | DR region manual spin-up (follow this runbook) |
| Vendor compromise (DrFirst, clearinghouse) | Rotate API keys; `/it-admin/clearinghouse-config` supports quick adapter swap |

## Breach-response integration

If the DR event is triggered BY a breach (not a natural outage), the DR procedure runs in parallel with the breach-notification runbook (see `breach-notification-runbook.md`). Do NOT restore compromised filesystems to production until forensics clears them.

## Operational owner

IT Admin lead holds operational ownership. Executive owns the annual DR test sign-off.
