<?php

// ─── DemoEnvironmentSeeder ────────────────────────────────────────────────────
// Top-level orchestrator for a complete demo tenant. Creates the demo Tenant +
// Sites + a full department / role staff roster, then chains every downstream
// demo + reference seeder (clinical, billing, day-center, compliance, etc.).
//
// When to run: dev / demo only. NEVER run against a production tenant — it
// fabricates participants, staff users, and PHI.
// Depends on: nothing (creates tenant + sites itself, then calls all others).
// Acronyms used elsewhere in the chain: PACE, IDT (Interdisciplinary Team),
// SDR (Service Delivery Request), CFR (Code of Federal Regulations).
// ─────────────────────────────────────────────────────────────────────────────

namespace Database\Seeders;

use App\Models\Site;
use App\Models\Tenant;
use App\Models\User;
use App\Services\ChatService;
use Illuminate\Database\Seeder;

class DemoEnvironmentSeeder extends Seeder
{
    private const DEPARTMENTS = [
        'primary_care'      => ['label' => 'Primary Care / Nursing',    'admin_first' => 'Margaret',  'standard_first' => 'Robert'],
        'therapies'         => ['label' => 'Therapies',                  'admin_first' => 'Patricia',  'standard_first' => 'James'],
        'social_work'       => ['label' => 'Social Work',                'admin_first' => 'Dorothy',   'standard_first' => 'Richard'],
        'behavioral_health' => ['label' => 'Behavioral Health',          'admin_first' => 'Gloria',    'standard_first' => 'Thomas'],
        'dietary'           => ['label' => 'Dietary / Nutrition',        'admin_first' => 'Helen',     'standard_first' => 'David'],
        'activities'        => ['label' => 'Activities / Recreation',    'admin_first' => 'Shirley',   'standard_first' => 'George'],
        'home_care'         => ['label' => 'Home Care',                  'admin_first' => 'Norma',     'standard_first' => 'Raymond'],
        'transportation'    => ['label' => 'Transportation',             'admin_first' => 'Barbara',   'standard_first' => 'Carlos'],
        'pharmacy'          => ['label' => 'Pharmacy',                   'admin_first' => 'Ruth',      'standard_first' => 'Harold'],
        'idt'               => ['label' => 'IDT / Care Coordination',   'admin_first' => 'Eleanor',   'standard_first' => 'Frank'],
        'enrollment'        => ['label' => 'Enrollment / Intake',        'admin_first' => 'Diane',     'standard_first' => 'Walter'],
        'finance'           => ['label' => 'Finance / Billing',          'admin_first' => 'Susan',     'standard_first' => 'William'],
        'qa_compliance'     => ['label' => 'QA / Compliance',            'admin_first' => 'Karen',     'standard_first' => 'Charles'],
        'it_admin'          => ['label' => 'IT / Administration',        'admin_first' => 'Nancy',     'standard_first' => 'Joseph'],
    ];

    public function run(): void
    {
        $this->command->info('');
        $this->command->info('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
        $this->command->info('  NostosEMR Demo Environment Seeder');
        $this->command->info('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');

        // ─── Tenant ───────────────────────────────────────────────────────────
        $tenant = Tenant::firstOrCreate(
            ['slug' => 'sunrise-pace-demo'],
            [
                'name'               => 'Sunrise PACE — Demo Organization',
                'transport_mode'     => 'direct',
                'cms_contract_id'    => 'H9999',
                'state'              => 'CA',
                'timezone'           => 'America/Los_Angeles',
                'auto_logout_minutes'=> 15,
                'is_active'          => true,
            ]
        );
        $this->command->line("  Tenant: <comment>{$tenant->name}</comment>");

        // ─── Sites ────────────────────────────────────────────────────────────
        $eastSite = Site::firstOrCreate(
            ['tenant_id' => $tenant->id, 'name' => 'Sunrise PACE East'],
            [
                'mrn_prefix' => 'EAST',
                'address'    => '1200 E Harbor Blvd',
                'city'       => 'Long Beach',
                'state'      => 'CA',
                'zip'        => '90802',
                'phone'      => '(562) 555-0100',
                'is_active'  => true,
            ]
        );
        // Backfill mrn_prefix if site already existed without it
        if (! $eastSite->mrn_prefix) {
            $eastSite->update(['mrn_prefix' => 'EAST']);
        }

        $westSite = Site::firstOrCreate(
            ['tenant_id' => $tenant->id, 'name' => 'Sunrise PACE West'],
            [
                'mrn_prefix' => 'WEST',
                'address'    => '4400 W Century Blvd',
                'city'       => 'Inglewood',
                'state'      => 'CA',
                'zip'        => '90304',
                'phone'      => '(310) 555-0200',
                'is_active'  => true,
            ]
        );
        if (! $westSite->mrn_prefix) {
            $westSite->update(['mrn_prefix' => 'WEST']);
        }
        $this->command->line("  Sites: <comment>{$eastSite->name}</comment>, <comment>{$westSite->name}</comment>");

        // ─── Users (2 per department = 28 total) ─────────────────────────────
        $this->command->info('');
        $this->command->info('  Creating 28 demo users (2 per department)...');
        $this->command->info('');

        $headers = ['Department', 'Role', 'Email'];
        $rows    = [];

        $sites  = [$eastSite, $westSite];
        // Alternate between light/dark so demo environment shows mixed theme preferences.
        // First 14 users get 'light', last 14 get 'dark' (one admin + one standard per dept = 28 users).
        $themeIndex = 0;

        foreach (self::DEPARTMENTS as $dept => $info) {
            foreach (['admin' => $info['admin_first'], 'standard' => $info['standard_first']] as $role => $firstName) {
                $email = strtolower($firstName) . '.' . $dept . '@sunrisepace-demo.test';
                $theme = ($themeIndex % 2 === 0) ? 'light' : 'dark';
                $themeIndex++;

                User::firstOrCreate(
                    ['email' => $email],
                    [
                        'tenant_id'        => $tenant->id,
                        'site_id'          => $sites[array_rand($sites)]->id,
                        'first_name'       => $firstName,
                        'last_name'        => 'Demo',
                        'department'       => $dept,
                        'role'             => $role,
                        'is_active'        => true,
                        'provisioned_at'   => now(),
                        'theme_preference' => $theme,
                    ]
                );

                $rows[] = [$info['label'], ucfirst($role), $email];
            }
        }

        $this->command->table($headers, $rows);

        // ─── Super Admin ──────────────────────────────────────────────────────
        // tj@nostos.tech — full access via Google OAuth, no department restriction
        User::firstOrCreate(
            ['email' => 'tj@nostos.tech'],
            [
                'tenant_id'      => $tenant->id,
                'site_id'        => $eastSite->id,
                'first_name'     => 'TJ',
                'last_name'      => 'Nostos',
                'department'     => 'it_admin',
                'role'           => 'super_admin',
                'is_active'      => true,
                'provisioned_at' => now(),
            ]
        );
        $this->command->line('  Super Admin: <comment>tj@nostos.tech</comment> (role: super_admin — unrestricted access)');

        // ─── Permissions ──────────────────────────────────────────────────────
        $this->command->info('');
        $this->command->info('  Seeding permission matrix...');
        $this->call(PermissionSeeder::class);

        // ─── Participants ─────────────────────────────────────────────────────
        $this->command->info('');
        $this->command->info('  Seeding 30 demo participants...');
        $this->call(ParticipantSeeder::class);

        // ─── ICD-10 Reference Data (Phase 3) ──────────────────────────────────
        $this->command->info('');
        $this->command->info('  Seeding ICD-10 reference codes...');
        $this->call(Icd10Seeder::class);

        // ─── Clinical Data (Phase 3) ──────────────────────────────────────────
        $this->command->info('');
        $this->command->info('  Seeding Phase 3 clinical data (notes, vitals, assessments, problems, allergies, ADL)...');
        $this->call(ClinicalDataSeeder::class);

        // ─── Phase 4 Data (Care Plans, IDT Meetings, SDRs, Alerts) ───────────
        $this->command->info('');
        $this->command->info('  Seeding Phase 4 data (care plans, IDT meetings, SDRs, alerts)...');
        $this->call(Phase4DataSeeder::class);

        // ─── Phase 5A Data (Locations + Appointments) ─────────────────────────
        $this->command->info('');
        $this->command->info('  Seeding Phase 5A data (locations, appointments)...');
        $this->call(Phase5ADataSeeder::class);

        // ─── Phase 5B Data (Transport Requests + Manifest) ────────────────────
        $this->command->info('');
        $this->command->info('  Seeding Phase 5B data (transport requests, manifest run sheet)...');
        $this->call(Phase5BDataSeeder::class);

        // ─── Phase 5C Data (Medications Reference + Participant Medications) ──
        $this->command->info('');
        $this->command->info('  Seeding Phase 5C data (medications reference, participant meds)...');
        $this->call(MedicationsReferenceSeeder::class);
        $this->call(Phase5CDataSeeder::class);

        // Phase R5 — wire previously-orphan reference seeders so a fresh
        // migrate:fresh --seed populates Beers Criteria, day-center schedule,
        // PRO survey definitions, quality-measure registry, HCC mapping.
        $this->command->info('');
        $this->command->info('  Seeding Wave R reference data (Beers Criteria, HCC mapping, day-center, PRO, QM)...');
        $this->call(BeersCriteriaSeeder::class);
        $this->call(HccMappingSeeder::class);
        $this->call(DayCenterScheduleSeeder::class);
        $this->call(ProSurveySeeder::class);
        $this->call(QualityMeasureSeeder::class);

        // ─── Phase 7C: Chat Channels ───────────────────────────────────────────
        // Auto-create 14 department channels + 1 broadcast channel for the tenant.
        // The super-admin (tj@nostos.tech) is used as the "created_by" user.
        $this->command->info('');
        $this->command->info('  Seeding Phase 7C data (chat channels)...');

        $createdBy = User::where('email', 'tj@nostos.tech')->first()
            ?? User::where('tenant_id', $tenant->id)->first();

        if ($createdBy) {
            app(ChatService::class)->createDepartmentChannels($tenant->id, $createdBy);
            $this->command->line('  Chat channels: <comment>14 department + 1 broadcast</comment>');
        }

        // ─── Phase 7D: Demo Polish Data ───────────────────────────────────────
        // Adds scenario-specific data: unsigned notes >24h, care plans due soon,
        // fall incident (RCA), enrollment referrals, chat seed messages, no-show.
        $this->command->info('');
        $this->command->info('  Seeding Phase 7D demo polish data...');
        $this->call(Phase7DDataSeeder::class);

        // ─── W3-7: Billing Demo Data ──────────────────────────────────────────
        // Seeds capitation records, HCC risk scores, encounter logs, PDE records,
        // and HOS-M surveys to demonstrate the billing dashboard math.
        $this->command->info('');
        $this->command->info('  Seeding W3-7 billing demo data...');
        $this->call(BillingDemoSeeder::class);

        // ─── W3-6: Site Transfer Demo Data ────────────────────────────────────
        // Seeds 3 participants with completed East → West transfers + clinical
        // notes at both sites, enabling the Site Transfer Integrity feature demo.
        $this->command->info('');
        $this->command->info('  Seeding W3-6 site transfer demo data...');
        $this->call(W3TransferSeeder::class);

        // ─── W4-2: Security & Compliance Demo Data ────────────────────────────
        // Seeds BAA records (AWS active, Mailgun expiring-soon, clearinghouse pending)
        // and 1 completed SRA with realistic HIPAA findings narrative.
        $this->command->info('');
        $this->command->info('  Seeding W4-2 security & compliance demo data...');
        $this->call(W42DataSeeder::class);

        // ─── W4-6: QAPI Projects + Significant Change Events ─────────────────
        // Seeds 2 active QAPI projects (satisfies CMS minimum per 42 CFR §460.136)
        // and demo significant change events for the IDT dashboard widget.
        $this->command->info('');
        $this->command->info('  Seeding W4-6 QAPI projects and significant change demo data...');
        $this->call(W46DataSeeder::class);

        // ─── W4-7: Clinical Orders Seed ───────────────────────────────────────
        // Seeds 2-4 routine orders per enrolled participant + 1 stat + 1 urgent
        // for the first 3 participants. Demonstrates CPOE worklist at /orders.
        // 42 CFR §460.90 — all PACE services must be ordered and documented.
        $this->command->info('');
        $this->command->info('  Seeding W4-7 clinical orders (CPOE demo data)...');
        $this->call(\Database\Seeders\ClinicalOrdersSeeder::class);

        // ─── W5-1: Wound Care + Break-the-Glass Demo Data ────────────────────
        // Seeds 4 open wound records (1 Stage 3 critical, 2 non-critical, 1 DFU
        // with assessments) and 3 BTG events (2 unreviewed + 1 acknowledged).
        // Demonstrates wound care widget on Primary Care/Home Care dashboards
        // and the Break-the-Glass widget on the IT Admin dashboard.
        $this->command->info('');
        $this->command->info('  Seeding W5-1 wound care and break-the-glass demo data...');
        $this->call(W51DataSeeder::class);

        // ─── W5-2: Lab Results Viewer ─────────────────────────────────────────
        // Seeds 5-8 lab results per enrolled participant with realistic CBC,
        // CMP, HbA1c, lipid, TSH, INR, and vitamin D panel data.
        // ~20% abnormal, ~5% critical-value, mix of reviewed/unreviewed.
        $this->command->info('');
        $this->command->info('  Seeding W5-2 lab results demo data...');
        $this->call(W52DataSeeder::class);

        // ─── W5-3: 835 Remittance + Denial Management ─────────────────────────
        // Seeds remittance batches with ERA 835 data, denial records across all
        // denial categories, and HCC-gap-driven revenue integrity demo data.
        $this->command->info('');
        $this->command->info('  Seeding W5-3 remittance and denial management demo data...');
        $this->call(W53DataSeeder::class);

        // --- W5-4: Tab CRUD Demo Data (consents, documents, immunizations, procedures, SDOH)
        // Seeds realistic data for the first 8 enrolled participants.
        $this->command->info('');
        $this->command->info('  Seeding W5-4 tab CRUD demo data (consents, docs, immunizations, procedures, SDOH)...');
        $this->call(W54DataSeeder::class);

        // ─── Day Center Attendance (past 2 weeks) ──────────────────────────────
        // Gives the Day Center page, summary endpoint, and activity dashboards
        // something real to display instead of empty rosters.
        $this->command->info('');
        $this->command->info('  Seeding day-center attendance for the past 14 days...');
        $this->call(DayCenterAttendanceSeeder::class);

        // ─── Phase 3 (MVP roadmap): CMS Level I/II quality indicators ─────────
        // Seeds incidents + immunizations for the past 4 quarters so the
        // Level I/II reporting dashboard has meaningful data to display.
        $this->command->info('');
        $this->command->info('  Seeding Level I/II quality indicators (4 quarters)...');
        $this->call(QualityIndicatorsSeeder::class);

        // ─── Phase 4 (MVP roadmap): §460.64-71 staff credentials ──────────────
        // Seeds TB + license + certification + immunization + training records
        // for every active staff user so the credentials UI + IT admin widget
        // show realistic data.
        $this->command->info('');
        $this->command->info('  Seeding staff credentials + training...');
        $this->call(StaffCredentialSeeder::class);

        // ─── Phase 6 (MVP roadmap): CMS MMR/TRR reconciliation demo data ──────
        // Generates a synthetic MMR + TRR for last month per tenant, with
        // intentional discrepancies so the finance reconciliation UI has
        // meaningful data to display.
        $this->command->info('');
        $this->command->info('  Seeding CMS MMR/TRR reconciliation demo files...');
        $this->call(CmsReconciliationSeeder::class);

        // ─── Medicaid Spend-Down / Share-of-Cost (Phase 7) ─────────────────────
        // Configures a handful of dual-eligible participants with state-specific
        // Medicaid spend-down obligations + payment history so the Insurance-tab
        // sub-panel and Finance "Spend-Down Overdue" widget have data to show.
        $this->command->info('');
        $this->command->info('  Seeding Medicaid spend-down / share-of-cost demo data...');
        $this->call(SpendDownDemoSeeder::class);

        // ─── Phase A1: previously-orphaned seeders now wired in ───────────────
        // These seeders existed but weren't being called from this master seeder;
        // tech-debt sweep added them for a proper demo.

        // Reference data seeders (safe + fast; idempotent).
        $this->command->info('');
        $this->command->info('  Seeding SNOMED + RxNorm lookup tables (Phase 13)...');
        $this->call(Phase13CodingSeeder::class);

        $this->command->info('');
        $this->command->info('  Seeding CARC claim-adjustment reason codes (for 835 remittance)...');
        $this->call(CarcCodeSeeder::class);

        // Back-fills.
        $this->command->info('');
        $this->command->info('  Linking PACE locations to their sites...');
        $this->call(LocationSiteLinkSeeder::class);

        // Participant-bound depth seeders (need participants + tenant already seeded above).
        $this->command->info('');
        $this->command->info('  Seeding additional scored assessments (Phase 13 instruments + baseline mix)...');
        $this->call(AssessmentDemoSeeder::class);

        $this->command->info('');
        $this->command->info('  Seeding HOS-M 2025 surveys...');
        $this->call(HosMSurvey2025DemoSeeder::class);

        $this->command->info('');
        $this->command->info('  Seeding demo referral notes (Phase 4+ enrollment feature)...');
        $this->call(ReferralNoteDemoSeeder::class);

        // Phase9BDataSeeder INTENTIONALLY not wired here — it overlaps with
        // BillingDemoSeeder (called earlier) and produces duplicate encounter
        // + capitation rows. Run standalone only, against a tenant that does
        // NOT already have BillingDemoSeeder data:
        //   ./vendor/bin/sail artisan db:seed --class=Phase9BDataSeeder

        $this->command->info('');
        $this->command->info('  Seeding Phase 14 demo depth (aging grievances, expiring credentials, pending appeals)...');
        $this->call(Phase14DemoDepthSeeder::class);

        // Phase B1 (MVP completion roadmap): restraint episodes demo
        $this->command->info('');
        $this->command->info('  Seeding restraint episodes demo data (Phase B1)...');
        $this->call(RestraintDemoSeeder::class);

        // Phase B2 (MVP completion roadmap): infection surveillance demo
        $this->command->info('');
        $this->command->info('  Seeding infection surveillance demo data (Phase B2)...');
        $this->call(InfectionDemoSeeder::class);

        // Phase B5 (MVP completion roadmap): drug-lab interaction reference
        $this->command->info('');
        $this->command->info('  Seeding drug-lab interaction reference (Phase B5)...');
        $this->call(DrugLabInteractionSeeder::class);

        // Phase B7 (MVP completion roadmap): system note templates (11 defaults)
        $this->command->info('');
        $this->command->info('  Seeding system note templates (Phase B7)...');
        $this->call(SystemNoteTemplateSeeder::class);

        // ─── Wave I-M demo data (Phase O9) ─────────────────────────────────────
        // Populates IADL, TB, Anticoagulation, ADE, Hospice, Discharge,
        // CareGap, GoalsOfCare, PredictiveRisk, Dietary, Activity, StaffTask,
        // and SavedDashboard rows for 2-3 enrolled demo participants per tenant.
        // Without this, every Wave I-N tab renders empty on a fresh demo seed.
        $this->command->info('');
        $this->command->info('  Seeding Wave I-M demo data (IADL/TB/Anticoag/ADE/Hospice/Discharge/...)...');
        $this->call(WaveIMDemoSeeder::class);

        // ─── Participant Photos ────────────────────────────────────────────────
        // Downloads pravatar.cc placeholder images for the first 15 enrolled
        // participants so the photo upload feature is visually testable.
        $this->command->info('');
        $this->command->info('  Seeding participant placeholder photos...');
        $this->call(ParticipantPhotoSeeder::class);

        $this->command->info('');
        $this->command->info('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
        $this->command->info('  Demo environment ready!');
        $this->command->info('  Login at: http://localhost/login');
        $this->command->info('  OTP emails: http://localhost:8025 (Mailpit)');
        $this->command->info('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
        $this->command->info('');
    }
}
