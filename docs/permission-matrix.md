# Permission Matrix

Source-of-truth: `database/seeders/PermissionSeeder.php` — this doc is a human-readable derivation.

## Roles
- **standard** — normal end-user
- **admin** — department head / power user
- **super_admin** — tenant-wide; break-glass + impersonation authority

## Departments (16)
primary_care · nursing · therapies · social_work · behavioral_health · dietary · activities · home_care · pharmacy · enrollment · finance · qa_compliance · it_admin · executive · super_admin · idt

## Access patterns (simplified)

| Surface | Allowed departments |
|---|---|
| Participant chart (read) | All clinical depts + enrollment + qa_compliance + it_admin + super_admin |
| Participant edit (demographics) | enrollment + it_admin + super_admin |
| Clinical notes (write) | primary_care + nursing + therapies + social_work + behavioral_health + dietary + pharmacy + home_care |
| Prescribe medication | primary_care + it_admin + super_admin |
| eMAR administer | primary_care + nursing + therapies + pharmacy |
| Drug interaction preview | primary_care + pharmacy + it_admin |
| Formulary maintenance | pharmacy + qa_compliance + it_admin + super_admin |
| Formulary read / check | All clinical depts + finance |
| Schedule appointment | All clinical depts + enrollment |
| File grievance | social_work + qa_compliance + it_admin |
| Appeals | qa_compliance + social_work + it_admin |
| Incidents | All clinical depts + qa_compliance |
| Build/run custom reports | qa_compliance + finance + executive + it_admin |
| Data import | it_admin + enrollment + qa_compliance |
| Clearinghouse config | it_admin + super_admin |
| Clearinghouse transmit | finance + it_admin + qa_compliance |
| State Medicaid submission | finance + qa_compliance + it_admin |
| HPMS Level I/II export | qa_compliance + finance + it_admin |
| QAPI committee records | qa_compliance + executive + it_admin |
| Spend-down payment recording | finance + enrollment + qa_compliance + it_admin |
| Mobile ADL page | home_care + nursing + therapies |
| Break-glass request | Any authenticated user (monthly review by IT+QA) |
| Audit log read | it_admin + super_admin |
| Security/BAA admin | it_admin + super_admin |
| User provisioning | it_admin + super_admin |
| SAML IdP config | it_admin + super_admin |
| HRIS webhook config | it_admin + super_admin |

## FHIR API scopes
Issued per OAuth client; stored on `emr_api_tokens.scopes`. Scope notation accepts both legacy (`patient.read`) and SMART (`patient/Patient.read`, `system/*.read`).

13 FHIR resources readable: Patient, Observation, MedicationRequest, Condition, AllergyIntolerance, CarePlan, Appointment, Immunization, Procedure, Encounter, DiagnosticReport, Practitioner, Organization.

## Designations (sub-roles within departments)
Set via JSON array on `shared_users.designations`. Drive targeted alerting:
- `medical_director` — gets critical NF-LOC / CMS-notification-overdue alerts
- `compliance_officer` — gets every grievance overdue + CMS-10003-due alert
- `pcp` — gets drug-interaction + CDS rule alerts for their panel
- `social_worker_lead` — gets appeals + hospice-referral escalations
- `nursing_director` — gets staff-credential-expiration + eMAR-late spikes
- `pharmacy_director` — gets coverage-determination pending + polypharmacy flags
- `qapi_chair` — gets QAPI annual deadline + committee-meeting reminders

## Changes
Any permission change = a new migration or a `PermissionSeeder` edit. This file must be updated at the same PR so the matrix stays current.
