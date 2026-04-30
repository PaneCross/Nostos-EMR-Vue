<?php

// ═══════════════════════════════════════════════════════════════════════════════
// NostosEMR — web routes
// ═══════════════════════════════════════════════════════════════════════════════
// All HTTP entry points to the application live in this file. Vue/Inertia pages
// are routed here (not separately). Public-facing API surfaces are explicitly
// marked. The file is organized into the following major sections:
//
//   Lines  ~96-108   — PUBLIC / GUEST: login, OTP request/verify, OAuth callbacks.
//   Lines ~110-1462  — AUTHENTICATED: the bulk of the app. Inside this group:
//                       • Department dashboards (one per PACE department)
//                       • Participant CRUD + 27 detail tabs (clinical chart)
//                       • Enrollment kanban + intake pipeline
//                       • Compliance audit-pull universes (CMS surveyor exports)
//                       • Clinical workflows: orders, notes, EMAR, BCMA, vitals
//                       • Pharmacy: meds, prior auth, formulary
//                       • Billing/Finance: capitation, encounters, EDI 837P/835
//                       • Transport: requests, manifest, vendor bridge
//                       • IT Admin: BAA/SRA, audit log, integrations, security
//                       • QA / QAPI: dashboard, KPIs, reports
//                       • Chat, Tasks, Reports, Profile, SuperAdmin (multi-tenant)
//   Lines ~1463-1507 — PARTICIPANT PORTAL: member-facing read-only + amend/ROI
//                       requests. Auth via X-Portal-User-Id header (Phase E1).
//   Lines ~1509-1626 — FHIR R4 API: SMART-on-FHIR / OAuth2 / Bearer token,
//                       used by external apps that consume our clinical data.
//                       42 CFR §170 / 21st Century Cures Act compliance.
//   Lines ~1628-1640 — INBOUND WEBHOOKS: transport status (HMAC-auth) +
//                       integration endpoints (tenant-header auth). Public
//                       per spec — vendor systems POST to these.
//   Lines ~1642-1644 — Health check (public).
//   Lines ~1645-end  — Test-only routes (Phase W2 — axios + Toaster behavioral
//                       end-to-end test wiring; SHOULD NOT EXIST in prod build).
//
// Cross-cutting conventions:
//   • Every authenticated route automatically tenant-scopes via SiteContext +
//     LogAuditEvent middleware. Controllers can rely on auth()->user() being a
//     real, active, dept-assigned user.
//   • Department gates are enforced INSIDE controllers (search for
//     CheckDepartmentAccess middleware or `requireXxx()` private methods).
//   • Resource routes (Route::resource) and named routes (->name(...)) are
//     used heavily; route names are referenced by the Vue side via Ziggy.
//
// Acronyms used in this file:
//   PACE = Programs of All-Inclusive Care for the Elderly (Medicare program 55+)
//   IDT  = Interdisciplinary Team (PACE clinical team)
//   EMAR = Electronic Medication Administration Record (per-dose nurse log)
//   BCMA = Bedside / Barcode Medication Administration
//   EDI  = Electronic Data Interchange (X12 healthcare claim format)
//   837P = the X12 transaction set for professional medical claims
//   835  = the X12 remittance / payment file (ERA from CMS)
//   FHIR = Fast Healthcare Interoperability Resources (modern HL7 REST API)
//   ROI  = Release of Information (HIPAA right-to-access; not return-on-investment)
//   BAA  = Business Associate Agreement (HIPAA vendor contract)
//   SRA  = Security Risk Analysis (annual HIPAA self-audit)
// ═══════════════════════════════════════════════════════════════════════════════

use App\Http\Controllers\AdlController;
use App\Http\Controllers\DisenrollmentController;
use App\Http\Controllers\BillingEncounterController;
use App\Http\Controllers\CapitationController;
use App\Http\Controllers\DocumentController;
use App\Http\Controllers\ChatController;
use App\Http\Controllers\EdiBatchController;
use App\Http\Controllers\HosMSurveyController;
use App\Http\Controllers\HpmsController;
use App\Http\Controllers\PdeController;
use App\Http\Controllers\RevenueIntegrityController;
use App\Http\Controllers\RiskAdjustmentController;
use App\Http\Controllers\BillingComplianceController;
use App\Http\Controllers\StateMedicaidConfigController;
use App\Http\Controllers\EhiExportController;
use App\Http\Controllers\BreakGlassController;
use App\Http\Controllers\ImmunizationController;
use App\Http\Controllers\ProcedureController;
use App\Http\Controllers\WoundController;
use App\Http\Controllers\DenialController;
use App\Http\Controllers\RemittanceController;
use App\Http\Controllers\LabResultController;
use App\Http\Controllers\SocialDeterminantController;
use App\Http\Controllers\DayCenterController;
use App\Http\Controllers\DayCenterScheduleController;
use App\Http\Controllers\ClinicalOrderController;
use App\Http\Controllers\ClinicalOverviewController;
use App\Http\Controllers\ReportsController;
use App\Http\Controllers\SystemSettingsController;
use App\Http\Controllers\TransferController;
use App\Http\Controllers\TransferAdminController;
use App\Http\Controllers\SiteContextController;
use App\Http\Controllers\TenantContextController;
use App\Http\Controllers\SuperAdminPanelController;
use App\Http\Controllers\Dashboards\ExecutiveDashboardController;
use App\Http\Controllers\OrgSettingsController;
use App\Http\Controllers\IntegrationController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ThemePreferenceController;
use App\Http\Controllers\ImpersonationController;
use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\IntegrationStatusController;
use App\Http\Controllers\UserProvisioningController;
use App\Http\Controllers\MedReconciliationController;
use App\Http\Controllers\MedicationController;
use App\Http\Controllers\AlertController;
use App\Http\Controllers\AllergyController;
use App\Http\Controllers\AppointmentController;
use App\Http\Controllers\AssessmentController;
use App\Http\Controllers\Auth\OtpController;
use App\Http\Controllers\Auth\SocialiteController;
use App\Http\Controllers\CarePlanController;
use App\Http\Controllers\ClinicalNoteController;
use App\Http\Controllers\TransportRequestController;
use App\Http\Controllers\WebhookController;
use App\Http\Controllers\ClinicalDashboardController;
use App\Http\Controllers\ComingSoonController;
use App\Http\Controllers\TransportController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\IdtMeetingController;
use App\Http\Controllers\LocationController;
use App\Http\Controllers\AddressController;
use App\Http\Controllers\ParticipantContactController;
use App\Http\Controllers\ParticipantController;
use App\Http\Controllers\ParticipantFlagController;
use App\Http\Controllers\ProblemController;
use App\Http\Controllers\FinanceController;
use App\Http\Controllers\FinanceDashboardController;
use App\Http\Controllers\FhirController;
use App\Http\Controllers\ConsentController;
use App\Http\Controllers\GrievanceController;
use App\Http\Controllers\SecurityComplianceController;
use App\Http\Controllers\IncidentController;
use App\Http\Controllers\QaDashboardController;
use App\Http\Controllers\QapiController;
use App\Http\Controllers\ReferralController;
use App\Http\Controllers\ReferralNoteController;
use App\Http\Controllers\SdrController;
use App\Http\Controllers\VitalController;
use App\Http\Controllers\Dashboards\PrimaryCareDashboardController;
use App\Http\Controllers\Dashboards\TherapiesDashboardController;
use App\Http\Controllers\Dashboards\SocialWorkDashboardController;
use App\Http\Controllers\Dashboards\BehavioralHealthDashboardController;
use App\Http\Controllers\Dashboards\DietaryDashboardController;
use App\Http\Controllers\Dashboards\ActivitiesDashboardController;
use App\Http\Controllers\Dashboards\HomeCareDashboardController;
use App\Http\Controllers\Dashboards\TransportationDashboardController;
use App\Http\Controllers\Dashboards\PharmacyDashboardController;
use App\Http\Controllers\Dashboards\IdtDashboardController;
use App\Http\Controllers\Dashboards\EnrollmentDashboardController;
use App\Http\Controllers\Dashboards\FinanceWidgetController;
use App\Http\Controllers\Dashboards\QaComplianceDashboardController;
use App\Http\Controllers\Dashboards\ItAdminDashboardController;
use Illuminate\Support\Facades\Route;

// ─── Public / Guest Routes ────────────────────────────────────────────────────

Route::middleware('guest')->group(function () {
    Route::get('/login', [OtpController::class, 'showLogin'])->name('login');

    // OTP Auth
    Route::post('/auth/request-otp', [OtpController::class, 'requestOtp'])->name('auth.otp.request');
    Route::post('/auth/verify-otp',  [OtpController::class, 'verifyOtp'])->name('auth.otp.verify');

    // Social Login
    Route::get('/auth/{provider}/redirect', [SocialiteController::class, 'redirect'])->name('auth.social.redirect');
    Route::get('/auth/{provider}/callback', [SocialiteController::class, 'callback'])->name('auth.social.callback');
});

// ─── Authenticated Routes ─────────────────────────────────────────────────────

Route::middleware('auth')->group(function () {
    // Logout (supports GET for timeout redirects and POST for manual logout)
    Route::match(['get', 'post'], '/auth/logout', [OtpController::class, 'logout'])->name('auth.logout'); // Named auth.logout — Fortify also registers 'logout'; duplicate names break route:cache

    // Phase P1 — touch session to extend idle timeout when user clicks "Stay signed in"
    Route::post('/auth/heartbeat', function () {
        return response()->json([
            'ok' => true,
            'session_lifetime_minutes' => (int) config('session.lifetime'),
            'expires_in_seconds' => (int) config('session.lifetime') * 60,
        ]);
    })->name('auth.heartbeat');

    // Root → redirect to department dashboard
    Route::get('/', [DashboardController::class, 'redirect'])->name('home');

    // Department dashboards
    Route::get('/dashboard/{department}', [DashboardController::class, 'show'])
        ->name('dept.dashboard')
        ->where('department', implode('|', [
            'primary_care', 'therapies', 'social_work', 'behavioral_health',
            'dietary', 'activities', 'home_care', 'transportation',
            'pharmacy', 'idt', 'enrollment', 'finance', 'qa_compliance', 'it_admin',
            'executive', 'super_admin',
        ]));

    // ─── Phase 7A: Department Dashboard Widget Endpoints (JSON) ──────────────
    // All widget endpoints are GET JSON — loaded in parallel client-side via Promise.all.
    // Each controller enforces its own dept guard (dept user or super_admin only).

    Route::prefix('dashboards')->group(function () {
        Route::prefix('primary-care')->group(function () {
            Route::get('/schedule',   [PrimaryCareDashboardController::class, 'schedule'])->name('dashboards.primary-care.schedule');
            Route::get('/alerts',     [PrimaryCareDashboardController::class, 'alerts'])->name('dashboards.primary-care.alerts');
            Route::get('/docs',       [PrimaryCareDashboardController::class, 'docs'])->name('dashboards.primary-care.docs');
            Route::get('/vitals',     [PrimaryCareDashboardController::class, 'vitals'])->name('dashboards.primary-care.vitals');
            // W4-7: CPOE orders widget — active orders for primary_care dept
            Route::get('/orders',     [PrimaryCareDashboardController::class, 'orders'])->name('dashboards.primary-care.orders');
            // W5-1: Open wound records for nursing review (CMS QAPI Stage 3+ threshold)
            Route::get('/wounds',     [PrimaryCareDashboardController::class, 'wounds'])->name('dashboards.primary-care.wounds');
            // W5-2: Unreviewed abnormal lab results for clinical attention
            Route::get('/lab-results', [PrimaryCareDashboardController::class, 'labResults'])->name('dashboards.primary-care.lab_results');
            // Phase I6 — new widgets
            Route::get('/care-gaps-rollup', [PrimaryCareDashboardController::class, 'careGapsRollup'])->name('dashboards.primary-care.care_gaps_rollup');
            Route::get('/high-risk-panel',  [PrimaryCareDashboardController::class, 'highRiskPanel'])->name('dashboards.primary-care.high_risk_panel');
            Route::get('/inr-overdue',      [PrimaryCareDashboardController::class, 'inrOverdue'])->name('dashboards.primary-care.inr_overdue');
        });

        Route::prefix('therapies')->group(function () {
            Route::get('/schedule',   [TherapiesDashboardController::class, 'schedule'])->name('dashboards.therapies.schedule');
            Route::get('/goals',      [TherapiesDashboardController::class, 'goals'])->name('dashboards.therapies.goals');
            Route::get('/sdrs',       [TherapiesDashboardController::class, 'sdrs'])->name('dashboards.therapies.sdrs');
            Route::get('/docs',       [TherapiesDashboardController::class, 'docs'])->name('dashboards.therapies.docs');
            // W4-7: CPOE orders widget — therapy orders (PT/OT/ST) for therapies dept
            Route::get('/orders',     [TherapiesDashboardController::class, 'orders'])->name('dashboards.therapies.orders');
        });

        Route::prefix('social-work')->group(function () {
            Route::get('/schedule',   [SocialWorkDashboardController::class, 'schedule'])->name('dashboards.social-work.schedule');
            Route::get('/alerts',     [SocialWorkDashboardController::class, 'alerts'])->name('dashboards.social-work.alerts');
            Route::get('/sdrs',       [SocialWorkDashboardController::class, 'sdrs'])->name('dashboards.social-work.sdrs');
            Route::get('/incidents',  [SocialWorkDashboardController::class, 'incidents'])->name('dashboards.social-work.incidents');
        });

        Route::prefix('behavioral-health')->group(function () {
            Route::get('/schedule',    [BehavioralHealthDashboardController::class, 'schedule'])->name('dashboards.behavioral-health.schedule');
            Route::get('/assessments', [BehavioralHealthDashboardController::class, 'assessments'])->name('dashboards.behavioral-health.assessments');
            Route::get('/sdrs',        [BehavioralHealthDashboardController::class, 'sdrs'])->name('dashboards.behavioral-health.sdrs');
            Route::get('/goals',       [BehavioralHealthDashboardController::class, 'goals'])->name('dashboards.behavioral-health.goals');
        });

        Route::prefix('dietary')->group(function () {
            Route::get('/assessments',  [DietaryDashboardController::class, 'assessments'])->name('dashboards.dietary.assessments');
            Route::get('/goals',        [DietaryDashboardController::class, 'goals'])->name('dashboards.dietary.goals');
            Route::get('/restrictions', [DietaryDashboardController::class, 'restrictions'])->name('dashboards.dietary.restrictions');
            Route::get('/sdrs',         [DietaryDashboardController::class, 'sdrs'])->name('dashboards.dietary.sdrs');
            // Phase I7
            Route::get('/orders-by-type',            [DietaryDashboardController::class, 'ordersByDietType'])->name('dashboards.dietary.orders_by_type');
            Route::get('/iadl-food-prep-candidates', [DietaryDashboardController::class, 'iadlFoodPrepCandidates'])->name('dashboards.dietary.iadl_food_prep_candidates');
        });

        Route::prefix('activities')->group(function () {
            Route::get('/schedule',   [ActivitiesDashboardController::class, 'schedule'])->name('dashboards.activities.schedule');
            Route::get('/goals',      [ActivitiesDashboardController::class, 'goals'])->name('dashboards.activities.goals');
            Route::get('/sdrs',       [ActivitiesDashboardController::class, 'sdrs'])->name('dashboards.activities.sdrs');
            Route::get('/docs',       [ActivitiesDashboardController::class, 'docs'])->name('dashboards.activities.docs');
        });

        Route::prefix('home-care')->group(function () {
            Route::get('/schedule',    [HomeCareDashboardController::class, 'schedule'])->name('dashboards.home-care.schedule');
            Route::get('/adl-alerts',  [HomeCareDashboardController::class, 'adlAlerts'])->name('dashboards.home-care.adl-alerts');
            Route::get('/goals',       [HomeCareDashboardController::class, 'goals'])->name('dashboards.home-care.goals');
            Route::get('/sdrs',        [HomeCareDashboardController::class, 'sdrs'])->name('dashboards.home-care.sdrs');
            // W5-1: Open wound records for home care nursing staff
            Route::get('/wounds',      [HomeCareDashboardController::class, 'wounds'])->name('dashboards.home-care.wounds');
            // Phase I7
            Route::get('/restraint-overdue',    [HomeCareDashboardController::class, 'restraintOverdue'])->name('dashboards.home-care.restraint_overdue');
            Route::get('/active-infections',    [HomeCareDashboardController::class, 'activeInfections'])->name('dashboards.home-care.active_infections');
            Route::get('/high-risk-caseload',   [HomeCareDashboardController::class, 'highRiskCaseload'])->name('dashboards.home-care.high_risk_caseload');
        });

        // ─── Phase 7B: Operations Department Dashboard Widget Endpoints ───────────

        Route::prefix('transportation')->group(function () {
            Route::get('/manifest-summary', [TransportationDashboardController::class, 'manifestSummary'])->name('dashboards.transportation.manifest-summary');
            Route::get('/add-ons',          [TransportationDashboardController::class, 'addOns'])->name('dashboards.transportation.add-ons');
            Route::get('/flag-alerts',      [TransportationDashboardController::class, 'flagAlerts'])->name('dashboards.transportation.flag-alerts');
            Route::get('/config',           [TransportationDashboardController::class, 'config'])->name('dashboards.transportation.config');
        });

        Route::prefix('pharmacy')->group(function () {
            Route::get('/med-changes',  [PharmacyDashboardController::class, 'medChanges'])->name('dashboards.pharmacy.med-changes');
            Route::get('/interactions', [PharmacyDashboardController::class, 'interactions'])->name('dashboards.pharmacy.interactions');
            Route::get('/controlled',   [PharmacyDashboardController::class, 'controlled'])->name('dashboards.pharmacy.controlled');
            Route::get('/refills',      [PharmacyDashboardController::class, 'refills'])->name('dashboards.pharmacy.refills');
            // W4-7: CPOE orders widget — medication_change orders for pharmacy dept
            Route::get('/orders',       [PharmacyDashboardController::class, 'orders'])->name('dashboards.pharmacy.orders');
            // Phase I6 — widget rollups
            Route::get('/bcma-overrides',      [PharmacyDashboardController::class, 'bcmaOverrides'])->name('dashboards.pharmacy.bcma_overrides');
            Route::get('/beers-rollup',        [PharmacyDashboardController::class, 'beersRollup'])->name('dashboards.pharmacy.beers_rollup');
            Route::get('/medwatch-deadlines',  [PharmacyDashboardController::class, 'medwatchDeadlines'])->name('dashboards.pharmacy.medwatch_deadlines');
            Route::get('/polypharmacy-queue',  [PharmacyDashboardController::class, 'polypharmacyQueue'])->name('dashboards.pharmacy.polypharmacy_queue');
        });

        Route::prefix('idt')->group(function () {
            Route::get('/meetings',     [IdtDashboardController::class, 'meetings'])->name('dashboards.idt.meetings');
            Route::get('/overdue-sdrs', [IdtDashboardController::class, 'overdueSdrs'])->name('dashboards.idt.overdue-sdrs');
            // Phase 2 (MVP roadmap): SDR SLA dual-clock widget (§460.121)
            Route::get('/sdr-sla',      [IdtDashboardController::class, 'sdrSla'])->name('dashboards.idt.sdr-sla');
            Route::get('/care-plans',   [IdtDashboardController::class, 'carePlans'])->name('dashboards.idt.care-plans');
            Route::get('/alerts',       [IdtDashboardController::class, 'alerts'])->name('dashboards.idt.alerts');
            // W4-5: 42 CFR §460.104(c) — IDT reassessment frequency tracking
            Route::get('/idt-review-overdue', [IdtDashboardController::class, 'idtReviewOverdue'])->name('dashboards.idt.idt-review-overdue');
            // W4-6: 42 CFR §460.104(b) — Significant change event 30-day IDT review tracking
            Route::get('/significant-changes', [IdtDashboardController::class, 'significantChanges'])->name('dashboards.idt.significant-changes');
        });

        Route::prefix('enrollment')->group(function () {
            Route::get('/pipeline',            [EnrollmentDashboardController::class, 'pipeline'])->name('dashboards.enrollment.pipeline');
            Route::get('/eligibility-pending', [EnrollmentDashboardController::class, 'eligibilityPending'])->name('dashboards.enrollment.eligibility-pending');
            Route::get('/disenrollments',      [EnrollmentDashboardController::class, 'disenrollments'])->name('dashboards.enrollment.disenrollments');
            Route::get('/new-referrals',       [EnrollmentDashboardController::class, 'newReferrals'])->name('dashboards.enrollment.new-referrals');
            // Phase 2 (MVP roadmap): NF-LOC recert widget (§460.160(b)(2))
            Route::get('/nf-loc-recert',       [EnrollmentDashboardController::class, 'nfLocRecert'])->name('dashboards.enrollment.nf-loc-recert');
        });

        Route::prefix('finance')->group(function () {
            Route::get('/capitation',          [FinanceWidgetController::class, 'capitation'])->name('dashboards.finance.capitation');
            Route::get('/authorizations',      [FinanceWidgetController::class, 'authorizations'])->name('dashboards.finance.authorizations');
            Route::get('/enrollment-changes',  [FinanceWidgetController::class, 'enrollmentChanges'])->name('dashboards.finance.enrollment-changes');
            Route::get('/encounters',          [FinanceWidgetController::class, 'encounters'])->name('dashboards.finance.encounters');
            // W5-3: Denial management widgets
            Route::get('/open-denials',        [FinanceWidgetController::class, 'openDenials'])->name('dashboards.finance.open-denials');
            Route::get('/revenue-at-risk',     [FinanceWidgetController::class, 'revenueAtRisk'])->name('dashboards.finance.revenue-at-risk');
            Route::get('/recent-remittance',   [FinanceWidgetController::class, 'recentRemittance'])->name('dashboards.finance.recent-remittance');
            // Phase 6 (MVP roadmap): CMS enrollment reconciliation widget
            Route::get('/cms-reconciliation',  [FinanceWidgetController::class, 'cmsReconciliation'])->name('dashboards.finance.cms-reconciliation');
            // Phase 7 (MVP roadmap): Medicaid spend-down overdue worklist
            Route::get('/spend-down-overdue',  [FinanceWidgetController::class, 'spendDownOverdue'])->name('dashboards.finance.spend-down-overdue');
        });

        Route::prefix('qa-compliance')->group(function () {
            Route::get('/metrics',     [QaComplianceDashboardController::class, 'metrics'])->name('dashboards.qa-compliance.metrics');
            Route::get('/incidents',   [QaComplianceDashboardController::class, 'incidents'])->name('dashboards.qa-compliance.incidents');
            Route::get('/docs',        [QaComplianceDashboardController::class, 'docs'])->name('dashboards.qa-compliance.docs');
            Route::get('/care-plans',  [QaComplianceDashboardController::class, 'carePlans'])->name('dashboards.qa-compliance.care-plans');
            // Phase 1 (MVP roadmap): §460.122 appeals widget
            Route::get('/appeals',     [QaComplianceDashboardController::class, 'appeals'])->name('dashboards.qa-compliance.appeals');
            // Phase I7
            Route::get('/sentinel-rollup',          [QaComplianceDashboardController::class, 'sentinelRollup'])->name('dashboards.qa-compliance.sentinel_rollup');
            Route::get('/critical-values-pending',  [QaComplianceDashboardController::class, 'criticalValuesPending'])->name('dashboards.qa-compliance.critical_values_pending');
            Route::get('/roi-due-soon',             [QaComplianceDashboardController::class, 'roiDueSoon'])->name('dashboards.qa-compliance.roi_due_soon');
            Route::get('/tb-overdue',               [QaComplianceDashboardController::class, 'tbOverdue'])->name('dashboards.qa-compliance.tb_overdue');
        });

        Route::prefix('it-admin')->group(function () {
            Route::get('/users',        [ItAdminDashboardController::class, 'users'])->name('dashboards.it-admin.users');
            Route::get('/integrations', [ItAdminDashboardController::class, 'integrations'])->name('dashboards.it-admin.integrations');
            Route::get('/audit',        [ItAdminDashboardController::class, 'audit'])->name('dashboards.it-admin.audit');
            Route::get('/config',       [ItAdminDashboardController::class, 'config'])->name('dashboards.it-admin.config');
            // W5-1: Break-the-glass emergency access events for HIPAA audit oversight
            Route::get('/break-glass',  [ItAdminDashboardController::class, 'breakGlass'])->name('dashboards.it-admin.break-glass');
            // Phase 4 (MVP roadmap): staff credential expiration widget (§460.71)
            Route::get('/expiring-credentials', [ItAdminDashboardController::class, 'expiringCredentials'])->name('dashboards.it-admin.expiring-credentials');
        });

        // ─── Phase 10B: Executive Dashboard Widget Endpoints ──────────────────
        // Executive dept users + isSuperAdmin + isDeptSuperAdmin. Pure JSON — no Inertia.
        // All 4 loaded in parallel by ExecutiveDashboard.tsx via Promise.all on mount.

        Route::prefix('executive')->group(function () {
            Route::get('/org-overview',       [ExecutiveDashboardController::class, 'orgOverview'])->name('dashboards.executive.org-overview');
            Route::get('/site-comparison',    [ExecutiveDashboardController::class, 'siteComparison'])->name('dashboards.executive.site-comparison');
            Route::get('/financial-overview', [ExecutiveDashboardController::class, 'financialOverview'])->name('dashboards.executive.financial-overview');
            Route::get('/sites-list',         [ExecutiveDashboardController::class, 'sitesList'])->name('dashboards.executive.sites-list');
            Route::get('/dept-compliance',   [ExecutiveDashboardController::class, 'deptCompliance'])->name('dashboards.executive.dept-compliance');
        });
    });

    // ─── Phase OS — Org Settings (executive-level notification preferences) ──
    // Cascade: org-level row (default tab) → optional per-site override rows
    // (additional tabs). See docs/internal/org-settings-design.md.
    Route::get   ('/executive/org-settings',                       [OrgSettingsController::class, 'index'])         ->name('executive.org-settings.index');
    Route::get   ('/executive/org-settings/site/{site}',           [OrgSettingsController::class, 'siteEffective']) ->name('executive.org-settings.site.effective');
    Route::post  ('/executive/org-settings',                       [OrgSettingsController::class, 'update'])        ->name('executive.org-settings.update');
    Route::delete('/executive/org-settings/site/{site}/key/{key}', [OrgSettingsController::class, 'clearOverride']) ->name('executive.org-settings.clear-override')
        ->where('key', '[a-z0-9_.]+');

    // ─── Credentials V1 — executive-managed catalog + dashboard ──────────────
    Route::get   ('/executive/job-titles-page',                             [\App\Http\Controllers\JobTitleController::class, 'page'])   ->name('executive.job-titles.page');
    Route::get   ('/executive/job-titles',                                  [\App\Http\Controllers\JobTitleController::class, 'index'])  ->name('executive.job-titles.index');
    Route::post  ('/executive/job-titles',                                  [\App\Http\Controllers\JobTitleController::class, 'store'])  ->name('executive.job-titles.store');
    Route::patch ('/executive/job-titles/{jobTitle}',                       [\App\Http\Controllers\JobTitleController::class, 'update']) ->name('executive.job-titles.update');
    Route::delete('/executive/job-titles/{jobTitle}',                       [\App\Http\Controllers\JobTitleController::class, 'destroy'])->name('executive.job-titles.destroy');

    Route::get   ('/executive/credentials-catalog',                                                     [\App\Http\Controllers\CredentialDefinitionController::class, 'page'])   ->name('executive.credentials-catalog.page');
    Route::get   ('/executive/credential-definitions/export',                                           [\App\Http\Controllers\CredentialDefinitionController::class, 'export']) ->name('executive.credential-definitions.export');
    Route::get   ('/executive/credential-definitions',                                                  [\App\Http\Controllers\CredentialDefinitionController::class, 'index'])  ->name('executive.credential-definitions.index');
    Route::post  ('/executive/credential-definitions',                                                  [\App\Http\Controllers\CredentialDefinitionController::class, 'store'])  ->name('executive.credential-definitions.store');
    Route::patch ('/executive/credential-definitions/{credentialDefinition}',                           [\App\Http\Controllers\CredentialDefinitionController::class, 'update']) ->name('executive.credential-definitions.update');
    Route::post  ('/executive/credential-definitions/{credentialDefinition}/clone',                     [\App\Http\Controllers\CredentialDefinitionController::class, 'clone'])  ->name('executive.credential-definitions.clone');
    Route::delete('/executive/credential-definitions/{credentialDefinition}',                           [\App\Http\Controllers\CredentialDefinitionController::class, 'destroy'])->name('executive.credential-definitions.destroy');
    Route::get   ('/executive/credential-definitions/preview-email-draft',                              [\App\Http\Controllers\CredentialDefinitionController::class, 'previewEmailDraft'])    ->name('executive.credential-definitions.preview-email-draft');
    Route::get   ('/executive/credential-definitions/{credentialDefinition}/preview-email',             [\App\Http\Controllers\CredentialDefinitionController::class, 'previewEmail'])         ->name('executive.credential-definitions.preview-email');
    Route::post  ('/executive/credential-definitions/{credentialDefinition}/site-overrides',            [\App\Http\Controllers\CredentialDefinitionController::class, 'storeSiteOverride'])  ->name('executive.credential-definitions.site-overrides.store');
    Route::delete('/executive/credential-definitions/{credentialDefinition}/site-overrides/{siteId}',   [\App\Http\Controllers\CredentialDefinitionController::class, 'destroySiteOverride'])->name('executive.credential-definitions.site-overrides.destroy');

    Route::get('/executive/credentials-dashboard',                                              [\App\Http\Controllers\CredentialsDashboardController::class, 'index'])    ->name('executive.credentials-dashboard');
    Route::get('/executive/credentials-dashboard/drilldown/{definitionId}/{department}/{bucket}',[\App\Http\Controllers\CredentialsDashboardController::class, 'drilldown'])->name('executive.credentials-dashboard.drilldown');

    // PDF/file streaming for staff credential docs (gated to admin or self)
    Route::get('/staff-credentials/{credential}/document', [\App\Http\Controllers\StaffCredentialController::class, 'downloadDocument'])->name('staff-credentials.document');

    // Self-service for the authenticated user
    Route::get ('/my-credentials',                              [\App\Http\Controllers\MyCredentialsController::class, 'index'])         ->name('my-credentials.index');
    Route::post('/my-credentials/{credential}/renewal',         [\App\Http\Controllers\MyCredentialsController::class, 'uploadRenewal'])->name('my-credentials.renewal');
    Route::post('/my-credentials/report-assignment',            [\App\Http\Controllers\MyCredentialsController::class, 'reportAssignment'])->name('my-credentials.report-assignment');

    // D7 : supervisor view of their direct reports' credential status
    Route::get ('/my-team',                                     [\App\Http\Controllers\MyTeamController::class, 'index'])->name('my-team.index');

    // V2: Bulk credentials CSV import (IT Admin only)
    Route::get ('/it-admin/credentials/bulk-import',            [\App\Http\Controllers\CredentialBulkImportController::class, 'page'])  ->name('it-admin.credentials.bulk-import.page');
    Route::post('/it-admin/credentials/bulk-import',            [\App\Http\Controllers\CredentialBulkImportController::class, 'import'])
        ->middleware('throttle:10,1')
        ->name('it-admin.credentials.bulk-import.run');

    // ─── Participant Module ───────────────────────────────────────────────────

    // Global search (JSON) — must be before the resource routes to avoid {id} conflict
    Route::get('/participants/search', [ParticipantController::class, 'search'])
        ->name('participants.search');

    // Participant CRUD
    Route::get('/participants',            [ParticipantController::class, 'index'])->name('participants.index');
    Route::post('/participants',           [ParticipantController::class, 'store'])->name('participants.store');
    Route::get('/participants/{participant}',    [ParticipantController::class, 'show'])->name('participants.show');
    Route::match(['PUT', 'PATCH'], '/participants/{participant}', [ParticipantController::class, 'update'])->name('participants.update');
    Route::delete('/participants/{participant}', [ParticipantController::class, 'destroy'])->name('participants.destroy');

    // Participant Photo
    Route::post('/participants/{participant}/photo',   [ParticipantController::class, 'uploadPhoto'])->name('participants.photo.upload');
    Route::delete('/participants/{participant}/photo', [ParticipantController::class, 'deletePhoto'])->name('participants.photo.delete');

    // Participant Flags
    Route::get('/participants/{participant}/flags',              [ParticipantFlagController::class, 'index'])->name('participants.flags.index');
    Route::post('/participants/{participant}/flags',             [ParticipantFlagController::class, 'store'])->name('participants.flags.store');
    Route::put('/participants/{participant}/flags/{flag}',       [ParticipantFlagController::class, 'update'])->name('participants.flags.update');
    Route::post('/participants/{participant}/flags/{flag}/resolve', [ParticipantFlagController::class, 'resolve'])->name('participants.flags.resolve');

    // Participant Addresses
    Route::post('/participants/{participant}/addresses',              [AddressController::class, 'store'])->name('participants.addresses.store');
    Route::put('/participants/{participant}/addresses/{address}',     [AddressController::class, 'update'])->name('participants.addresses.update');

    // Participant Contacts
    Route::get('/participants/{participant}/contacts',            [ParticipantContactController::class, 'index'])->name('participants.contacts.index');
    Route::post('/participants/{participant}/contacts',           [ParticipantContactController::class, 'store'])->name('participants.contacts.store');
    Route::put('/participants/{participant}/contacts/{contact}',  [ParticipantContactController::class, 'update'])->name('participants.contacts.update');

    // ─── Phase 3: Clinical Documentation (nested under participant) ───────────
    // All clinical records are scoped to a participant for tenant isolation.

    Route::prefix('participants/{participant}')->group(function () {

        // Clinical Notes — SOAP + 7 other templates; signed notes are immutable
        Route::get('/notes',                          [ClinicalNoteController::class, 'index'])->name('participants.notes.index');
        Route::post('/notes',                         [ClinicalNoteController::class, 'store'])->name('participants.notes.store');
        Route::get('/notes/{note}',                   [ClinicalNoteController::class, 'show'])->name('participants.notes.show');
        Route::put('/notes/{note}',                   [ClinicalNoteController::class, 'update'])->name('participants.notes.update');
        Route::post('/notes/{note}/sign',             [ClinicalNoteController::class, 'sign'])->name('participants.notes.sign');
        Route::post('/notes/{note}/addendum',         [ClinicalNoteController::class, 'addendum'])->name('participants.notes.addendum');

        // Vitals — append-only; trends endpoint for Recharts
        Route::get('/vitals',                         [VitalController::class, 'index'])->name('participants.vitals.index');
        Route::post('/vitals',                        [VitalController::class, 'store'])->name('participants.vitals.store');
        Route::get('/vitals/trends',                  [VitalController::class, 'trends'])->name('participants.vitals.trends');

        // Assessments — scored structured tools (PHQ-9, MoCA, Barthel, etc.)
        Route::get('/assessments',                    [AssessmentController::class, 'index'])->name('participants.assessments.index');
        Route::post('/assessments',                   [AssessmentController::class, 'store'])->name('participants.assessments.store');
        Route::get('/assessments/due',                [AssessmentController::class, 'due'])->name('participants.assessments.due');
        Route::get('/assessments/{assessment}',       [AssessmentController::class, 'show'])->name('participants.assessments.show');

        // Problem List — ICD-10 coded, grouped by status
        Route::get('/problems',                       [ProblemController::class, 'index'])->name('participants.problems.index');
        Route::post('/problems',                      [ProblemController::class, 'store'])->name('participants.problems.store');
        Route::put('/problems/{problem}',             [ProblemController::class, 'update'])->name('participants.problems.update');
        Route::delete('/problems/{problem}',          [ProblemController::class, 'destroy'])->name('participants.problems.destroy');

        // Allergies & Dietary Restrictions — life-threatening banner driven by severity
        Route::get('/allergies',                      [AllergyController::class, 'index'])->name('participants.allergies.index');
        Route::post('/allergies',                     [AllergyController::class, 'store'])->name('participants.allergies.store');
        Route::put('/allergies/{allergy}',            [AllergyController::class, 'update'])->name('participants.allergies.update');
        Route::delete('/allergies/{allergy}',         [AllergyController::class, 'destroy'])->name('participants.allergies.destroy');

        // ADL Tracking — append-only records; configurable breach thresholds
        Route::get('/adl',                            [AdlController::class, 'index'])->name('participants.adl.index');
        Route::post('/adl',                           [AdlController::class, 'store'])->name('participants.adl.store');
        Route::put('/adl/thresholds',                 [AdlController::class, 'updateThresholds'])->name('participants.adl.thresholds');
    });

    // ─── W4-7: Clinical Orders / CPOE (nested under participant) ────────────────
    // 42 CFR §460.90 — all PACE services must be ordered and documented.
    // Prescribers create orders; target_department is auto-set via DEPARTMENT_ROUTING.
    Route::prefix('participants/{participant}/orders')->group(function () {
        Route::get('/',                         [ClinicalOrderController::class, 'index'])->name('participants.orders.index');
        Route::post('/',                        [ClinicalOrderController::class, 'store'])->name('participants.orders.store');
        Route::get('/{order}',                  [ClinicalOrderController::class, 'show'])->name('participants.orders.show');
        Route::patch('/{order}',                [ClinicalOrderController::class, 'update'])->name('participants.orders.update');
        Route::post('/{order}/acknowledge',     [ClinicalOrderController::class, 'acknowledge'])->name('participants.orders.acknowledge');
        Route::post('/{order}/result',          [ClinicalOrderController::class, 'result'])->name('participants.orders.result');
        Route::post('/{order}/complete',        [ClinicalOrderController::class, 'complete'])->name('participants.orders.complete');
        Route::post('/{order}/cancel',          [ClinicalOrderController::class, 'cancel'])->name('participants.orders.cancel');
    });

    // Cross-participant orders worklist (replaces clinical.orders → care-plan-goals stub)
    // Was: GET /clinical/orders → ClinicalOverviewController::orders() (showed care plan goals)
    // Now: GET /orders → ClinicalOrderController::worklist() (real CPOE worklist)
    Route::get('/orders', [ClinicalOrderController::class, 'worklist'])->name('orders.worklist');

    // ─── Phase 5C: Medications + eMAR (nested under participant) ─────────────
    Route::prefix('participants/{participant}')->group(function () {
        // Medication list
        Route::get('/medications',                                             [MedicationController::class, 'index'])->name('participants.medications.index');
        Route::post('/medications',                                            [MedicationController::class, 'store'])->name('participants.medications.store');
        Route::put('/medications/{medication}/discontinue',                    [MedicationController::class, 'discontinue'])->name('participants.medications.discontinue');
        // Drug interaction alerts
        Route::get('/medications/interactions',                                [MedicationController::class, 'interactions'])->name('participants.medications.interactions');
        Route::post('/medications/interactions/{alert}/acknowledge', [MedicationController::class, 'acknowledgeInteraction'])->name('participants.medications.interactions.acknowledge');
        // eMAR
        Route::get('/emar',                                                    [MedicationController::class, 'emarIndex'])->name('participants.emar.index');
        Route::post('/emar/{record}/administer',                               [MedicationController::class, 'administer'])->name('participants.emar.administer');
        Route::post('/medications/{medication}/prn-dose',                      [MedicationController::class, 'recordPrnDose'])->name('participants.medications.prn-dose');
        // ── Phase 5D: Medication Reconciliation (5-step workflow) ────────────────
        // These replace the Phase 5C stub reconciliation endpoints.
        // Ordered so static segment /start comes before implicit /{rec} patterns.
        Route::post('/med-reconciliation/start',         [MedReconciliationController::class, 'start'])->name('participants.med-reconciliation.start');
        Route::post('/med-reconciliation/prior-meds',    [MedReconciliationController::class, 'savePriorMeds'])->name('participants.med-reconciliation.prior-meds');
        Route::get('/med-reconciliation/comparison',     [MedReconciliationController::class, 'comparison'])->name('participants.med-reconciliation.comparison');
        Route::post('/med-reconciliation/decisions',     [MedReconciliationController::class, 'decisions'])->name('participants.med-reconciliation.decisions');
        Route::post('/med-reconciliation/approve',       [MedReconciliationController::class, 'approve'])->name('participants.med-reconciliation.approve');
        Route::get('/med-reconciliation/history',        [MedReconciliationController::class, 'history'])->name('participants.med-reconciliation.history');
    });

    // ─── Phase 8A: Documents (nested under participant) ──────────────────────
    // Upload, list, stream-download, and soft-delete participant documents.
    // File path is never exposed to the client — downloads go through the controller.
    Route::prefix('participants/{participant}/documents')->group(function () {
        Route::get('/',                          [DocumentController::class, 'index'])->name('participants.documents.index');
        Route::post('/',                         [DocumentController::class, 'store'])->name('participants.documents.store');
        Route::get('/{document}/download',       [DocumentController::class, 'download'])->name('participants.documents.download');
        Route::delete('/{document}',             [DocumentController::class, 'destroy'])->name('participants.documents.destroy');
    });

    // ─── Phase 6A: Disenrollment (nested under participant) ──────────────────
    Route::post('/participants/{participant}/disenroll', [ReferralController::class, 'disenroll'])->name('participants.disenroll');
    Route::post('/participants/{participant}/reenroll', [ReferralController::class, 'reenroll'])->name('participants.reenroll');

    // ─── W4-5: Disenrollment record (42 CFR §460.116 transition plan) ─────────
    Route::prefix('participants/{participant}/disenrollment')->group(function () {
        Route::get('/',   [DisenrollmentController::class, 'show'])->name('participants.disenrollment.show');
        Route::patch('/', [DisenrollmentController::class, 'update'])->name('participants.disenrollment.update');
    });

    // ─── Phase 11B: Immunizations (nested under participant) ─────────────────
    Route::prefix('participants/{participant}/immunizations')->group(function () {
        Route::get('/',   [ImmunizationController::class, 'index'])->name('participants.immunizations.index');
        Route::post('/',  [ImmunizationController::class, 'store'])->name('participants.immunizations.store');
    });

    // ─── Phase 11B: Social Determinants (nested under participant) ────────────
    Route::prefix('participants/{participant}/social-determinants')->group(function () {
        Route::get('/',   [SocialDeterminantController::class, 'index'])->name('participants.social_determinants.index');
        Route::post('/',  [SocialDeterminantController::class, 'store'])->name('participants.social_determinants.store');
    });

    // ─── Phase 11B: Procedures (nested under participant) ────────────────────
    Route::prefix('participants/{participant}/procedures')->group(function () {
        Route::get('/',   [ProcedureController::class, 'index'])->name('participants.procedures.index');
        Route::post('/',  [ProcedureController::class, 'store'])->name('participants.procedures.store');
    });

    // ─── W5-1: Wound Care (nested under participant) ──────────────────────────
    // Write access: home_care, primary_care, therapies, it_admin.
    // Read access: all authenticated users with participant access.
    Route::prefix('participants/{participant}/wounds')->group(function () {
        Route::get('/',                          [WoundController::class, 'index'])->name('participants.wounds.index');
        Route::post('/',                         [WoundController::class, 'store'])->name('participants.wounds.store');
        Route::get('/{wound}',                   [WoundController::class, 'show'])->name('participants.wounds.show');
        Route::put('/{wound}',                   [WoundController::class, 'update'])->name('participants.wounds.update');
        Route::post('/{wound}/assess',           [WoundController::class, 'addAssessment'])->name('participants.wounds.assess');
        Route::post('/{wound}/close',            [WoundController::class, 'close'])->name('participants.wounds.close');
    });

    // ─── W5-2: Lab Results Viewer (nested under participant) ─────────────────
    // Write (store): primary_care, home_care, therapies, it_admin.
    // Review: primary_care, it_admin.
    // Read: all authenticated users with participant access.
    Route::prefix('participants/{participant}/lab-results')->group(function () {
        Route::get('/',            [LabResultController::class, 'index'])->name('participants.lab_results.index');
        Route::post('/',           [LabResultController::class, 'store'])->name('participants.lab_results.store');
        Route::get('/{lab}',       [LabResultController::class, 'show'])->name('participants.lab_results.show');
        Route::post('/{lab}/review', [LabResultController::class, 'review'])->name('participants.lab_results.review');
    });

    // ─── W5-1: Break-the-Glass Emergency Access (nested under participant) ────
    // HIPAA 45 CFR §164.312(a)(2)(ii) emergency access override.
    // Rate-limited: 3 requests per user per 24 hours (BreakGlassService).
    Route::post('/participants/{participant}/break-glass', [BreakGlassController::class, 'requestAccess'])->name('participants.break_glass.request');

    // Phase 5 (MVP roadmap): Policy surface pages — info-blocking, NPP, acceptable-use
    Route::get('/policies/info-blocking',  [\App\Http\Controllers\PolicyController::class, 'infoBlocking'])->name('policies.info-blocking');
    Route::get('/policies/npp',            [\App\Http\Controllers\PolicyController::class, 'noticeOfPrivacyPractices'])->name('policies.npp');
    Route::get('/policies/acceptable-use', [\App\Http\Controllers\PolicyController::class, 'acceptableUse'])->name('policies.acceptable-use');

    // ─── Phase 11B: EHI Export (nested under participant) ────────────────────
    // POST generates a new export (returns 202 with download URL).
    // GET download validates the token and streams the ZIP (410 Gone if expired).
    // Phase 5 (MVP roadmap): Inertia page + history JSON for self-service flow
    Route::get ('/participants/{participant}/ehi-export',         [EhiExportController::class, 'index'])->name('participants.ehi_export.index');
    Route::get ('/participants/{participant}/ehi-export/history', [EhiExportController::class, 'history'])->name('participants.ehi_export.history');
    Route::post('/participants/{participant}/ehi-export',         [EhiExportController::class, 'request'])->name('participants.ehi_export.request');
    Route::get ('/participants/{participant}/ehi-export/{token}/download', [EhiExportController::class, 'download'])->name('participants.ehi_export.download');

    // Phase 7 (MVP roadmap): Medicaid spend-down / share-of-cost
    Route::get ('/participants/{participant}/spend-down',            [\App\Http\Controllers\SpendDownController::class, 'show'])->name('participants.spend_down.show');
    Route::post('/participants/{participant}/spend-down/coverage',   [\App\Http\Controllers\SpendDownController::class, 'updateCoverage'])->name('participants.spend_down.coverage.update');
    Route::post('/participants/{participant}/spend-down/payments',   [\App\Http\Controllers\SpendDownController::class, 'storePayment'])->name('participants.spend_down.payments.store');
    Route::delete('/spend-down/payments/{payment}',                  [\App\Http\Controllers\SpendDownController::class, 'destroyPayment'])->name('spend_down.payments.destroy');

    // Phase 8 (MVP roadmap): IIS HL7 VXU + C-CDA + Advance directive PDF
    Route::post('/participants/{participant}/immunizations/{immunization}/iis-submit',
        [\App\Http\Controllers\ImmunizationSubmissionController::class, 'store'])
        ->name('participants.immunizations.iis_submit');
    Route::get ('/participants/{participant}/iis-submissions',
        [\App\Http\Controllers\ImmunizationSubmissionController::class, 'index'])
        ->name('participants.iis_submissions.index');
    Route::get ('/participants/{participant}/ccda/export',
        [\App\Http\Controllers\CcdaController::class, 'export'])
        ->name('participants.ccda.export');
    Route::post('/participants/{participant}/ccda/import',
        [\App\Http\Controllers\CcdaController::class, 'import'])
        ->name('participants.ccda.import');
    // Phase M3 — HIE gateway
    Route::post('/participants/{participant}/hie/publish',
        [\App\Http\Controllers\HieController::class, 'publish'])->name('hie.publish');
    Route::get('/participants/{participant}/hie/documents',
        [\App\Http\Controllers\HieController::class, 'documents'])->name('hie.documents');
    Route::get('/hie/ccd/{participant}',
        [\App\Http\Controllers\HieController::class, 'ccd'])->name('hie.ccd');
    Route::get ('/participants/{participant}/advance-directive/pdf',
        [\App\Http\Controllers\AdvanceDirectivePdfController::class, 'generate'])
        ->name('participants.advance_directive.pdf');
    // Phase M1 — wizard
    Route::post('/participants/{participant}/advance-directive',
        [\App\Http\Controllers\AdvanceDirectiveWizardController::class, 'store'])
        ->name('participants.advance_directive.store');
    Route::get('/participants/{participant}/advance-directive/wizard',
        fn (\App\Models\Participant $participant) =>
            \Inertia\Inertia::render('AdvanceDirective/Wizard', ['participant' => $participant])
        )->name('participants.advance_directive.wizard');

    // Phase 15 (MVP roadmap): medium-term wins batch — formulary, reports, imports, CDS, committees, HRIS, Medicaid, mobile
    // 15.10 Formulary
    Route::get   ('/formulary',                          [\App\Http\Controllers\FormularyController::class, 'index'])->name('formulary.index');
    Route::post  ('/formulary',                          [\App\Http\Controllers\FormularyController::class, 'store'])->name('formulary.store');
    Route::put   ('/formulary/{entry}',                  [\App\Http\Controllers\FormularyController::class, 'update'])->name('formulary.update');
    Route::get   ('/formulary/check',                    [\App\Http\Controllers\FormularyController::class, 'check'])->name('formulary.check');
    Route::post  ('/participants/{participant}/coverage-determinations', [\App\Http\Controllers\FormularyController::class, 'storeDetermination'])->name('formulary.determinations.store');
    // 15.3 Custom reports — /reports is the canned catalog; custom builder lives at /reports/custom
    Route::get   ('/reports/custom',                     [\App\Http\Controllers\ReportDefinitionController::class, 'builder'])->name('reports.custom.builder');
    Route::get   ('/reports/custom/definitions',         [\App\Http\Controllers\ReportDefinitionController::class, 'index'])->name('reports.custom.index');
    Route::post  ('/reports',                            [\App\Http\Controllers\ReportDefinitionController::class, 'store'])->name('reports.store');
    Route::post  ('/reports/{definition}/run',           [\App\Http\Controllers\ReportDefinitionController::class, 'run'])->name('reports.run');
    Route::get   ('/reports/{definition}/download',      [\App\Http\Controllers\ReportDefinitionController::class, 'download'])->name('reports.download');
    Route::delete('/reports/{definition}',               [\App\Http\Controllers\ReportDefinitionController::class, 'destroy'])->name('reports.destroy');
    // 15.4 Data migration toolkit
    Route::get   ('/data-imports',                       [\App\Http\Controllers\DataImportController::class, 'index'])->name('data_imports.index');
    Route::post  ('/data-imports',                       [\App\Http\Controllers\DataImportController::class, 'store'])->name('data_imports.store');
    Route::post  ('/data-imports/{import}/commit',       [\App\Http\Controllers\DataImportController::class, 'commit'])->name('data_imports.commit');
    Route::get   ('/data-imports/template/{entity}',     [\App\Http\Controllers\DataImportController::class, 'template'])->name('data_imports.template');
    // 15.6 Clinical decision support
    Route::post  ('/participants/{participant}/cds/evaluate', [\App\Http\Controllers\ClinicalDecisionSupportController::class, 'evaluate'])->name('cds.evaluate');

    // Phase B1 (MVP completion roadmap): Restraints documentation
    Route::get ('/participants/{participant}/restraints',
        [\App\Http\Controllers\RestraintController::class, 'index'])->name('participants.restraints.index');
    Route::post('/participants/{participant}/restraints',
        [\App\Http\Controllers\RestraintController::class, 'store'])->name('participants.restraints.store');
    Route::post('/participants/{participant}/restraints/{episode}/observations',
        [\App\Http\Controllers\RestraintController::class, 'storeObservation'])->name('participants.restraints.observations.store');
    Route::post('/participants/{participant}/restraints/{episode}/discontinue',
        [\App\Http\Controllers\RestraintController::class, 'discontinue'])->name('participants.restraints.discontinue');
    Route::post('/participants/{participant}/restraints/{episode}/idt-review',
        [\App\Http\Controllers\RestraintController::class, 'recordIdtReview'])->name('participants.restraints.idt-review');

    // Phase G9 (MVP completion roadmap): Advanced BI — report builder + saved dashboards
    Route::get ('/bi/schema',                 [\App\Http\Controllers\AdvancedBiController::class, 'schema'])->name('bi.schema');
    Route::post('/bi/report',                 [\App\Http\Controllers\AdvancedBiController::class, 'runReport'])->name('bi.report');
    Route::get ('/bi/dashboards',             [\App\Http\Controllers\AdvancedBiController::class, 'dashboardsIndex'])->name('bi.dashboards.index');
    Route::post('/bi/dashboards',             [\App\Http\Controllers\AdvancedBiController::class, 'dashboardsStore'])->name('bi.dashboards.store');
    Route::get ('/bi/dashboards/{dashboard}', [\App\Http\Controllers\AdvancedBiController::class, 'dashboardsShow'])->name('bi.dashboards.show');

    // Phase K2 — Inertia BI UI pages
    Route::get('/bi/builder',    fn () => \Inertia\Inertia::render('Bi/ReportBuilder'))->name('bi.builder.ui');
    Route::get('/bi/saved',      fn () => \Inertia\Inertia::render('Bi/Dashboards'))->name('bi.dashboards.ui');

    // Phase G8 (MVP completion roadmap): Predictive modeling
    Route::get ('/participants/{participant}/predictive-risk',          [\App\Http\Controllers\PredictiveRiskController::class, 'forParticipant'])->name('predictive.for_participant');
    Route::post('/participants/{participant}/predictive-risk/compute',  [\App\Http\Controllers\PredictiveRiskController::class, 'compute'])->name('predictive.compute');
    Route::post('/predictive-risk/recompute-all',                       [\App\Http\Controllers\PredictiveRiskController::class, 'recomputeAll'])->name('predictive.recompute_all');
    Route::get ('/dashboards/high-risk',                                [\App\Http\Controllers\PredictiveRiskController::class, 'highRisk'])->name('dashboards.high_risk');

    // Phase K1 — Inertia dashboard pages (Chart.js rendered)
    Route::get('/dashboards/quality',  fn () => \Inertia\Inertia::render('Dashboards/QualityMeasures'))->name('dashboards.quality.ui');
    Route::get('/dashboards/gaps',     fn () => \Inertia\Inertia::render('Dashboards/CareGaps'))->name('dashboards.gaps.ui');
    // Phase O3 deleted: /dashboards/risk — canonical is /dashboards/high-risk
    // which now dual-serves JSON + Inertia via wantsJson() branch.

    // Phase K3 — Operational pages
    Route::get('/ops/panel',       fn () => \Inertia\Inertia::render('Operations/Panel'))->name('ops.panel.ui');
    Route::get('/ops/dietary',     fn () => \Inertia\Inertia::render('Operations/DietaryOrders'))->name('ops.dietary.ui');
    Route::get('/ops/activities',  fn () => \Inertia\Inertia::render('Operations/ActivitiesCalendar'))->name('ops.activities.ui');
    Route::get('/ops/huddle',      fn () => \Inertia\Inertia::render('Operations/Huddle'))->name('ops.huddle.ui');

    // Phase O3 deleted: /registries-ui/* — canonical is /registries/{r} which
    // now dual-serves JSON + Inertia via wantsJson() branch on DiseaseRegistryController::show.

    // Phase G7 (MVP completion roadmap): PRO surveys
    Route::get ('/pro/surveys',                         [\App\Http\Controllers\ProController::class, 'surveys'])->name('pro.surveys');
    Route::post('/pro/responses',                       [\App\Http\Controllers\ProController::class, 'storeResponse'])->name('pro.responses.store');
    Route::get ('/participants/{participant}/pro-trend',[\App\Http\Controllers\ProController::class, 'trend'])->name('pro.trend');

    // Phase G6 (MVP completion roadmap): Document OCR + search
    Route::post('/documents/{document}/ocr', [\App\Http\Controllers\OcrController::class, 'process'])->name('documents.ocr');
    Route::get ('/documents/search',         [\App\Http\Controllers\OcrController::class, 'search'])->name('documents.search');

    // Phase G5 (MVP completion roadmap): Team huddle dashboard
    Route::get ('/huddle',     [\App\Http\Controllers\TeamHuddleController::class, 'show'])->name('huddle.show');
    Route::get ('/huddle/pdf', [\App\Http\Controllers\TeamHuddleController::class, 'pdf'])->name('huddle.pdf');

    // Phase G4 (MVP completion roadmap): Consent templates + versioning
    Route::get ('/consent-templates',                    [\App\Http\Controllers\ConsentTemplateController::class, 'index'])->name('consent_templates.index');
    Route::post('/consent-templates',                    [\App\Http\Controllers\ConsentTemplateController::class, 'store'])->name('consent_templates.store');
    Route::post('/consent-templates/{template}/approve', [\App\Http\Controllers\ConsentTemplateController::class, 'approve'])->name('consent_templates.approve');
    Route::get ('/consent-templates/reprompt-queue',     [\App\Http\Controllers\ConsentTemplateController::class, 'repromptQueue'])->name('consent_templates.reprompt_queue');

    // Phase G3 (MVP completion roadmap): HEDIS/Stars quality measures
    Route::get ('/quality-measures',           [\App\Http\Controllers\QualityMeasureController::class, 'index'])->name('quality_measures.index');
    Route::get ('/quality-measures/snapshots', [\App\Http\Controllers\QualityMeasureController::class, 'snapshots'])->name('quality_measures.snapshots');
    Route::post('/quality-measures/compute',   [\App\Http\Controllers\QualityMeasureController::class, 'compute'])->name('quality_measures.compute');

    // Phase G2 (MVP completion roadmap): Disease registries
    Route::get ('/registries/{registry}',       [\App\Http\Controllers\DiseaseRegistryController::class, 'show'])->name('registries.show');
    Route::get ('/registries/{registry}/export',[\App\Http\Controllers\DiseaseRegistryController::class, 'export'])->name('registries.export');

    // Phase G1 (MVP completion roadmap): Care gaps + readmission risk
    Route::get ('/care-gaps/summary',                    [\App\Http\Controllers\CareGapController::class, 'summary'])->name('care_gaps.summary');
    Route::get ('/care-gaps/my-panel',                   [\App\Http\Controllers\CareGapController::class, 'myPanel'])->name('care_gaps.my_panel');
    Route::post('/care-gaps/recompute-all',              [\App\Http\Controllers\CareGapController::class, 'recomputeAll'])->name('care_gaps.recompute_all');
    Route::get ('/participants/{participant}/care-gaps', [\App\Http\Controllers\CareGapController::class, 'forParticipant'])->name('care_gaps.for_participant');
    Route::get ('/dashboards/readmission-risk',          [\App\Http\Controllers\CareGapController::class, 'readmissionRisk'])->name('dashboards.readmission_risk');

    // Phase F2 (MVP completion roadmap): Short wins batch 2
    Route::get ('/participants/{participant}/beers-flags', [\App\Http\Controllers\ShortWinsF2Controller::class, 'beersFlags'])->name('f2.beers_flags');
    Route::post('/participants/{participant}/smartsets/{key}', [\App\Http\Controllers\ShortWinsF2Controller::class, 'applySmartSet'])->name('f2.smartset');
    Route::get ('/notes/{note}/pdf',                 [\App\Http\Controllers\ShortWinsF2Controller::class, 'notePdf'])->name('f2.note_pdf');
    Route::get ('/search/filters',                   [\App\Http\Controllers\ShortWinsF2Controller::class, 'searchFilters'])->name('f2.search_filters');
    Route::post('/care-plans/bulk-sign',              [\App\Http\Controllers\ShortWinsF2Controller::class, 'bulkSignCarePlans'])->name('f2.bulk_sign_care_plans');
    Route::get ('/wristbands/center-print.pdf',      [\App\Http\Controllers\ShortWinsF2Controller::class, 'centerWristbandPdf'])->name('f2.center_wristbands');
    Route::get ('/participants/{participant}/timeline', [\App\Http\Controllers\ShortWinsF2Controller::class, 'timeline'])->name('f2.timeline');
    Route::get ('/note-reminders/upcoming',          [\App\Http\Controllers\ShortWinsF2Controller::class, 'noteRemindersUpcoming'])->name('f2.note_reminders');

    // Phase F1 (MVP completion roadmap): Short wins batch 1
    Route::get ('/widgets/immunization-forecast', [\App\Http\Controllers\ShortWinsF1Controller::class, 'immunizationForecast'])->name('widgets.immunization_forecast');
    Route::get ('/widgets/late-doses',            [\App\Http\Controllers\ShortWinsF1Controller::class, 'lateDoseTrend'])->name('widgets.late_doses');
    Route::get ('/wounds/{wound}/photos',         [\App\Http\Controllers\ShortWinsF1Controller::class, 'wondPhotosIndex'])->name('wounds.photos.index');
    Route::post('/wounds/{wound}/photos',         [\App\Http\Controllers\ShortWinsF1Controller::class, 'woundPhotosStore'])->name('wounds.photos.store');
    Route::get ('/participants/{participant}/goals-of-care',  [\App\Http\Controllers\ShortWinsF1Controller::class, 'goalsOfCareIndex'])->name('goc.index');
    Route::post('/participants/{participant}/goals-of-care',  [\App\Http\Controllers\ShortWinsF1Controller::class, 'goalsOfCareStore'])->name('goc.store');

    // Phase D4 (MVP completion roadmap): Activities programming
    Route::get ('/activities',                  [\App\Http\Controllers\ActivityController::class, 'index'])->name('activities.index');
    Route::post('/activities',                  [\App\Http\Controllers\ActivityController::class, 'store'])->name('activities.store');
    Route::post('/activities/{event}/attendance', [\App\Http\Controllers\ActivityController::class, 'recordAttendance'])->name('activities.attendance');
    Route::get ('/participants/{participant}/activity-trend',
        [\App\Http\Controllers\ActivityController::class, 'participantTrend'])->name('activities.trend');

    // Phase D3 (MVP completion roadmap): Dietary orders
    Route::get ('/participants/{participant}/dietary-orders',
        [\App\Http\Controllers\DietaryOrderController::class, 'index'])->name('dietary.index');
    Route::post('/participants/{participant}/dietary-orders',
        [\App\Http\Controllers\DietaryOrderController::class, 'store'])->name('dietary.store');
    Route::post('/dietary-orders/{order}/discontinue',
        [\App\Http\Controllers\DietaryOrderController::class, 'discontinue'])->name('dietary.discontinue');
    Route::get ('/dietary/roster',
        [\App\Http\Controllers\DietaryOrderController::class, 'roster'])->name('dietary.roster');

    // Phase D2 (MVP completion roadmap): Staff task queue
    Route::get ('/tasks',                  [\App\Http\Controllers\StaffTaskController::class, 'index'])->name('tasks.index');
    Route::post('/tasks',                  [\App\Http\Controllers\StaffTaskController::class, 'store'])->name('tasks.store');
    Route::post('/tasks/{task}/complete',  [\App\Http\Controllers\StaffTaskController::class, 'complete'])->name('tasks.complete');
    Route::post('/tasks/{task}/cancel',    [\App\Http\Controllers\StaffTaskController::class, 'cancel'])->name('tasks.cancel');

    // Phase D1 (MVP completion roadmap): PCP panel management
    Route::get ('/panel/my',       [\App\Http\Controllers\PanelController::class, 'mine'])->name('panel.mine');
    Route::get ('/panel/sizes',    [\App\Http\Controllers\PanelController::class, 'sizes'])->name('panel.sizes');
    Route::post('/panel/assign',   [\App\Http\Controllers\PanelController::class, 'assign'])->name('panel.assign');
    Route::post('/panel/transfer', [\App\Http\Controllers\PanelController::class, 'transfer'])->name('panel.transfer');

    // Phase C5 (MVP completion roadmap): Adverse Drug Events
    Route::get ('/participants/{participant}/ade',
        [\App\Http\Controllers\AdverseDrugEventController::class, 'index'])->name('ade.index');
    Route::post('/participants/{participant}/ade',
        [\App\Http\Controllers\AdverseDrugEventController::class, 'store'])->name('ade.store');
    Route::post('/ade/{ade}/mark-reported',
        [\App\Http\Controllers\AdverseDrugEventController::class, 'markReported'])->name('ade.mark_reported');

    // Phase C4 (MVP completion roadmap): Structured discharge workflow
    Route::get ('/participants/{participant}/discharge-events',
        [\App\Http\Controllers\DischargeEventController::class, 'index'])->name('discharge.index');
    Route::post('/participants/{participant}/discharge-events',
        [\App\Http\Controllers\DischargeEventController::class, 'store'])->name('discharge.store');
    Route::post('/discharge-events/{event}/items/{key}/complete',
        [\App\Http\Controllers\DischargeEventController::class, 'completeItem'])->name('discharge.complete_item');

    // Phase C3 (MVP completion roadmap): Hospice workflow + bereavement
    Route::post('/participants/{participant}/hospice/refer',
        [\App\Http\Controllers\HospiceController::class, 'refer'])->name('hospice.refer');
    Route::post('/participants/{participant}/hospice/enroll',
        [\App\Http\Controllers\HospiceController::class, 'enroll'])->name('hospice.enroll');
    Route::post('/participants/{participant}/hospice/idt-review',
        [\App\Http\Controllers\HospiceController::class, 'idtReview'])->name('hospice.idt_review');
    Route::post('/participants/{participant}/hospice/death',
        [\App\Http\Controllers\HospiceController::class, 'recordDeath'])->name('hospice.death');
    Route::get ('/participants/{participant}/bereavement-contacts',
        [\App\Http\Controllers\HospiceController::class, 'bereavementIndex'])->name('hospice.bereavement.index');
    Route::post('/bereavement-contacts/{contact}/complete',
        [\App\Http\Controllers\HospiceController::class, 'completeBereavement'])->name('hospice.bereavement.complete');

    // Phase C2a (MVP completion roadmap): TB screening (§460.71)
    Route::get ('/participants/{participant}/tb-screenings',
        [\App\Http\Controllers\TbScreeningController::class, 'index'])->name('participants.tb.index');
    Route::post('/participants/{participant}/tb-screenings',
        [\App\Http\Controllers\TbScreeningController::class, 'store'])->name('participants.tb.store');

    // Phase C1 (MVP completion roadmap): IADL assessments (Lawton scale)
    Route::get ('/participants/{participant}/iadl',
        [\App\Http\Controllers\IadlController::class, 'index'])->name('participants.iadl.index');
    Route::post('/participants/{participant}/iadl',
        [\App\Http\Controllers\IadlController::class, 'store'])->name('participants.iadl.store');

    // Phase B8a (MVP completion roadmap): E-signature on consents
    Route::post('/participants/{participant}/consents/{consent}/sign',
        [\App\Http\Controllers\ConsentController::class, 'sign'])->name('participant.consents.sign');
    Route::get ('/participants/{participant}/consents/{consent}/signed.pdf',
        [\App\Http\Controllers\ConsentController::class, 'signedPdf'])->name('participant.consents.signed_pdf');

    // Phase B8b (MVP completion roadmap): ROI requests
    Route::get ('/participants/{participant}/roi-requests',
        [\App\Http\Controllers\RoiRequestController::class, 'index'])->name('participants.roi.index');
    Route::post('/participants/{participant}/roi-requests',
        [\App\Http\Controllers\RoiRequestController::class, 'store'])->name('participants.roi.store');
    Route::post('/roi-requests/{roi}/update-status',
        [\App\Http\Controllers\RoiRequestController::class, 'updateStatus'])->name('roi.update_status');

    // Phase B6 (MVP completion roadmap): Critical-value acknowledgment
    Route::post('/critical-values/{ack}/acknowledge',
        [\App\Http\Controllers\VitalController::class, 'acknowledge'])->name('critical_values.acknowledge');
    Route::get('/participants/{participant}/critical-values',
        [\App\Http\Controllers\VitalController::class, 'pendingCriticalValues'])->name('critical_values.pending');

    // Phase P2 — HIPAA §164.528 Accounting of Disclosures
    Route::get('/participants/{participant}/phi-disclosures',
        [\App\Http\Controllers\PhiDisclosureController::class, 'forParticipant'])
        ->name('phi_disclosures.for_participant');
    Route::get('/it-admin/phi-disclosures',
        [\App\Http\Controllers\PhiDisclosureController::class, 'index'])
        ->name('it_admin.phi_disclosures.index');

    // Phase P3 — HIPAA §164.526 Right to Amend
    Route::get('/compliance/amendments',
        [\App\Http\Controllers\AmendmentRequestController::class, 'index'])
        ->name('compliance.amendments.index');
    Route::post('/participants/{participant}/amendment-requests',
        [\App\Http\Controllers\AmendmentRequestController::class, 'store'])
        ->name('amendment_requests.store');
    Route::post('/amendment-requests/{amendmentRequest}/decide',
        [\App\Http\Controllers\AmendmentRequestController::class, 'decide'])
        ->name('amendment_requests.decide');

    // Phase P5 — X12 270/271 eligibility
    Route::get('/participants/{participant}/eligibility-checks',
        [\App\Http\Controllers\EligibilityCheckController::class, 'index'])->name('eligibility.index');
    Route::post('/participants/{participant}/eligibility-checks',
        [\App\Http\Controllers\EligibilityCheckController::class, 'store'])->name('eligibility.store');

    // Phase P6 — Prior Authorization workflow
    Route::get('/pharmacy/prior-auth',
        [\App\Http\Controllers\PriorAuthRequestController::class, 'queue'])->name('prior_auth.queue');
    Route::post('/participants/{participant}/prior-auth',
        [\App\Http\Controllers\PriorAuthRequestController::class, 'store'])->name('prior_auth.store');
    Route::post('/prior-auth/{priorAuthRequest}/transition',
        [\App\Http\Controllers\PriorAuthRequestController::class, 'transition'])->name('prior_auth.transition');

    // Phase R10 — clinician-accessible per-participant RAF + HCC V28 visibility
    Route::get('/participants/{participant}/raf-snapshot',
        [\App\Http\Controllers\ParticipantRafController::class, 'show'])->name('participants.raf_snapshot');

    // Phase R9 — marketing / referral-source / lead funnel
    Route::get('/enrollment/marketing-funnel',
        [\App\Http\Controllers\MarketingFunnelController::class, 'index'])->name('enrollment.marketing_funnel');

    // Phase S2 — Contracted-provider network + per-contract rates
    Route::get('/network/contracted-providers',
        [\App\Http\Controllers\ContractedProviderController::class, 'index'])->name('network.contracted_providers.index');
    Route::post('/network/contracted-providers',
        [\App\Http\Controllers\ContractedProviderController::class, 'store'])->name('network.contracted_providers.store');
    Route::post('/network/contracted-providers/{contractedProvider}/contracts',
        [\App\Http\Controllers\ContractedProviderController::class, 'storeContract'])->name('network.contracted_providers.contracts.store');
    Route::get('/network/contracts/{contract}/rates',
        [\App\Http\Controllers\ContractedProviderController::class, 'showRates'])->name('network.contracts.rates.index');
    Route::post('/network/contracts/{contract}/rates',
        [\App\Http\Controllers\ContractedProviderController::class, 'storeRate'])->name('network.contracts.rates.store');

    // Phase S5 — IBNR (Incurred But Not Reported) estimator
    Route::get('/billing/ibnr',
        [\App\Http\Controllers\IbnrController::class, 'index'])->name('billing.ibnr.index');

    // Phase S4 — Encounter Data Management (CMS EDS) submission gateway
    Route::get('/billing/encounter-data-submission',
        [\App\Http\Controllers\EncounterDataSubmissionController::class, 'index'])->name('billing.encounter_data_submission.index');
    Route::post('/billing/encounter-data-submission/{batch}/submit',
        [\App\Http\Controllers\EncounterDataSubmissionController::class, 'submit'])->name('billing.encounter_data_submission.submit');

    // Phase S3 — DME tracking
    Route::get('/network/dme',
        [\App\Http\Controllers\DmeController::class, 'index'])->name('network.dme.index');
    Route::post('/network/dme',
        [\App\Http\Controllers\DmeController::class, 'store'])->name('network.dme.store');
    Route::post('/network/dme/{dme}/issue',
        [\App\Http\Controllers\DmeController::class, 'issue'])->name('network.dme.issue');
    Route::post('/network/dme/issuances/{issuance}/return',
        [\App\Http\Controllers\DmeController::class, 'return_'])->name('network.dme.return');

    // Phase R11 — CMS PACE Audit Protocol 2.0 universe pulls (3-attempt limit)
    Route::get('/compliance/cms-audit-universes',
        [\App\Http\Controllers\CmsAuditUniverseController::class, 'index'])->name('compliance.cms_audit_universes.index');
    Route::get('/compliance/cms-audit-universes/{universe}.csv',
        [\App\Http\Controllers\CmsAuditUniverseController::class, 'export'])->name('compliance.cms_audit_universes.export');

    // Phase R8 — HPMS Incident Reports (5 CMS-aligned exports)
    Route::get('/compliance/hpms-incident-reports',
        [\App\Http\Controllers\HpmsIncidentReportController::class, 'index'])->name('compliance.hpms_incident_reports.index');
    Route::get('/compliance/hpms-incident-reports/{report}.csv',
        [\App\Http\Controllers\HpmsIncidentReportController::class, 'export'])->name('compliance.hpms_incident_reports.export');

    // Phase P4 — HIPAA §164.404 Breach Notification
    Route::get('/it-admin/breaches',
        [\App\Http\Controllers\BreachIncidentController::class, 'index'])->name('it_admin.breaches.index');
    Route::post('/it-admin/breaches',
        [\App\Http\Controllers\BreachIncidentController::class, 'store'])->name('it_admin.breaches.store');
    Route::post('/it-admin/breaches/{breachIncident}/individuals-notified',
        [\App\Http\Controllers\BreachIncidentController::class, 'markIndividualsNotified'])->name('it_admin.breaches.individuals_notified');
    Route::post('/it-admin/breaches/{breachIncident}/hhs-notified',
        [\App\Http\Controllers\BreachIncidentController::class, 'markHhsNotified'])->name('it_admin.breaches.hhs_notified');
    Route::get('/it-admin/breaches/{breachIncident}/letter/{participant}',
        [\App\Http\Controllers\BreachIncidentController::class, 'generateLetter'])->name('it_admin.breaches.letter');

    // Phase B7 (MVP completion roadmap): Note templates + problem-based charting
    Route::get   ('/note-templates',
        [\App\Http\Controllers\NoteTemplateController::class, 'index'])->name('note_templates.index');
    Route::post  ('/note-templates',
        [\App\Http\Controllers\NoteTemplateController::class, 'store'])->name('note_templates.store');
    Route::put   ('/note-templates/{template}',
        [\App\Http\Controllers\NoteTemplateController::class, 'update'])->name('note_templates.update');
    Route::delete('/note-templates/{template}',
        [\App\Http\Controllers\NoteTemplateController::class, 'destroy'])->name('note_templates.destroy');
    Route::get   ('/note-templates/{template}/render/{participant}',
        [\App\Http\Controllers\NoteTemplateController::class, 'render'])->name('note_templates.render');
    Route::get   ('/problems/{problem}/notes',
        [\App\Http\Controllers\ClinicalNoteController::class, 'notesForProblem'])->name('problems.notes');

    // Phase B5 (MVP completion roadmap): Anticoagulation plans + INR
    Route::get ('/participants/{participant}/anticoagulation',
        [\App\Http\Controllers\AnticoagulationController::class, 'index'])->name('anticoag.index');
    Route::post('/participants/{participant}/anticoagulation/plans',
        [\App\Http\Controllers\AnticoagulationController::class, 'storePlan'])->name('anticoag.plans.store');
    Route::post('/anticoagulation-plans/{plan}/stop',
        [\App\Http\Controllers\AnticoagulationController::class, 'stopPlan'])->name('anticoag.plans.stop');
    Route::post('/participants/{participant}/anticoagulation/inr',
        [\App\Http\Controllers\AnticoagulationController::class, 'recordInr'])->name('anticoag.inr.store');

    // Phase B4 (MVP completion roadmap): BCMA scan-verify + wristband PDF
    Route::post('/emar/{record}/scan-verify',
        [\App\Http\Controllers\MedicationController::class, 'scanVerify'])->name('emar.scan_verify');
    Route::get('/participants/{participant}/wristband.pdf',
        [\App\Http\Controllers\WristbandController::class, 'show'])->name('participants.wristband');

    // Phase B2 (MVP completion roadmap): Infection surveillance + outbreak detection
    Route::get ('/participants/{participant}/infections',
        [\App\Http\Controllers\InfectionCaseController::class, 'index'])->name('participants.infections.index');
    Route::post('/participants/{participant}/infections',
        [\App\Http\Controllers\InfectionCaseController::class, 'store'])->name('participants.infections.store');
    Route::post('/infections/{case}/resolve',
        [\App\Http\Controllers\InfectionCaseController::class, 'resolve'])->name('infections.resolve');
    Route::post('/infection-outbreaks/{outbreak}/update',
        [\App\Http\Controllers\InfectionCaseController::class, 'updateOutbreak'])->name('infection_outbreaks.update');
    // 15.8 Committees
    Route::get   ('/committees',                         [\App\Http\Controllers\CommitteeController::class, 'index'])->name('committees.index');
    Route::post  ('/committees',                         [\App\Http\Controllers\CommitteeController::class, 'store'])->name('committees.store');
    Route::post  ('/committees/{committee}/members',     [\App\Http\Controllers\CommitteeController::class, 'addMember'])->name('committees.members.add');
    Route::post  ('/committees/{committee}/meetings',    [\App\Http\Controllers\CommitteeController::class, 'scheduleMeeting'])->name('committees.meetings.schedule');
    Route::patch ('/committee-meetings/{meeting}',       [\App\Http\Controllers\CommitteeController::class, 'recordMeeting'])->name('committees.meetings.record');
    Route::post  ('/committee-meetings/{meeting}/votes', [\App\Http\Controllers\CommitteeController::class, 'recordVote'])->name('committees.votes.record');
    // 15.9 State Medicaid submissions
    Route::post  ('/state-medicaid/batches/{batch}/stage/{stateCode}',
        [\App\Http\Controllers\StateMedicaidSubmissionController::class, 'stageForState'])->name('state_medicaid.stage');
    Route::get   ('/state-medicaid/submissions',
        [\App\Http\Controllers\StateMedicaidSubmissionController::class, 'index'])->name('state_medicaid.submissions.index');
    // 15.5 Mobile companion
    // Phase O8 — /home-care/mobile-adl replaced by the canonical /mobile entry.
    // Redirect preserves any existing bookmarks. Original MobileCompanionController deleted.
    Route::get('/home-care/mobile-adl', fn () => redirect('/mobile'))->name('home_care.mobile_adl');
    // Phase M5 — day list PWA
    Route::get('/mobile',           [\App\Http\Controllers\MobileHomeVisitsController::class, 'index'])->name('mobile.index');
    Route::get('/mobile/today',     [\App\Http\Controllers\MobileHomeVisitsController::class, 'todayJson'])->name('mobile.today');

    // Phase M6 — billing reconciliation dashboards
    Route::get('/billing/pde-reconciliation.json',        [\App\Http\Controllers\BillingReconciliationController::class, 'pdeJson'])->name('billing.pde.json');
    Route::get('/billing/capitation-reconciliation.json', [\App\Http\Controllers\BillingReconciliationController::class, 'capitationJson'])->name('billing.capitation.json');
    Route::get('/dashboards/pde-reconciliation',          fn () => \Inertia\Inertia::render('Dashboards/PdeReconciliation'))->name('dashboards.pde.ui');
    Route::get('/dashboards/capitation-reconciliation',   fn () => \Inertia\Inertia::render('Dashboards/CapitationReconciliation'))->name('dashboards.capitation.ui');

    // Phase 14 (MVP roadmap): printable PDFs + appointment detail + global search
    Route::get ('/participants/{participant}/pdf/{kind}',
        [\App\Http\Controllers\ParticipantPdfController::class, 'generate'])
        ->name('participants.pdf');
    Route::get ('/appointments/{appointment}',
        [\App\Http\Controllers\AppointmentController::class, 'showStandalone'])
        ->name('appointments.show');
    Route::get ('/search',
        [\App\Http\Controllers\GlobalSearchController::class, 'index'])
        ->name('search.global');

    // Phase 13 (MVP roadmap): coding lookups + scored instruments + pre-save drug interaction check
    Route::get ('/coding/snomed',  [\App\Http\Controllers\CodingLookupController::class, 'snomed'])->name('coding.snomed');
    Route::get ('/coding/rxnorm',  [\App\Http\Controllers\CodingLookupController::class, 'rxnorm'])->name('coding.rxnorm');
    Route::get ('/assessment-instruments',              [\App\Http\Controllers\AssessmentInstrumentController::class, 'index'])->name('assessment_instruments.index');
    Route::get ('/assessment-instruments/{instrument}', [\App\Http\Controllers\AssessmentInstrumentController::class, 'show'])->name('assessment_instruments.show');
    Route::post('/assessment-instruments/{instrument}/score', [\App\Http\Controllers\AssessmentInstrumentController::class, 'score'])->name('assessment_instruments.score');
    Route::post('/participants/{participant}/medications/interaction-preview',
        [\App\Http\Controllers\DrugInteractionPreviewController::class, 'preview'])->name('participants.medications.interaction_preview');

    // Phase 12 (MVP roadmap): Clearinghouse transmission
    Route::post('/clearinghouse/batches/{batch}/transmit',
        [\App\Http\Controllers\ClearinghouseTransmissionController::class, 'transmit'])
        ->name('clearinghouse.transmit');
    Route::get ('/clearinghouse/batches/{batch}/transmissions',
        [\App\Http\Controllers\ClearinghouseTransmissionController::class, 'history'])
        ->name('clearinghouse.history');

    // ─── Phase 10A: Site Transfers (nested under participant) ─────────────────
    // View history: any authenticated user (dept check in controller for write ops).
    // Request/Approve/Cancel: enrollment + it_admin + super_admin only.
    Route::prefix('participants/{participant}/transfers')->group(function () {
        Route::get('/',                         [TransferController::class, 'index'])->name('participants.transfers.index');
        Route::get('/sites',                    [TransferController::class, 'sites'])->name('participants.transfers.sites');
        Route::get('/summary',                  [TransferController::class, 'summary'])->name('participants.transfers.summary');
        Route::post('/verify',                  [TransferController::class, 'verify'])->name('participants.transfers.verify');
        Route::post('/',                        [TransferController::class, 'request'])->name('participants.transfers.request');
        Route::post('/{transfer}/approve',      [TransferController::class, 'approve'])->name('participants.transfers.approve');
        Route::post('/{transfer}/cancel',       [TransferController::class, 'cancel'])->name('participants.transfers.cancel');
    });

    // ─── Phase 10A: Transfer Admin Page ──────────────────────────────────────
    // Enrollment + IT Admin only. Lists all tenant transfers with status filter.
    Route::get('/enrollment/transfers', [TransferAdminController::class, 'index'])->name('enrollment.transfers.index');

    // ICD-10 Lookup — tenant-agnostic reference data; used by problem typeahead
    Route::get('/icd10/search', [ProblemController::class, 'icd10Search'])->name('icd10.search');

    // Medication reference search — typeahead for "Add Medication" modal (Phase 5C)
    Route::get('/medications/reference/search', [MedicationController::class, 'referenceSearch'])->name('medications.reference.search');

    // ─── Phase 3: Care Plan (nested under participant) ────────────────────────
    Route::prefix('participants/{participant}/careplan')->group(function () {
        Route::get('/',                         [CarePlanController::class, 'show'])->name('participants.careplan.show');
        Route::post('/',                        [CarePlanController::class, 'store'])->name('participants.careplan.store');
        Route::get('/{carePlan}',              [CarePlanController::class, 'showVersion'])->name('participants.careplan.version');
        Route::put('/{carePlan}/goals/{domain}', [CarePlanController::class, 'upsertGoal'])->name('participants.careplan.goal');
        Route::post('/{carePlan}/approve',     [CarePlanController::class, 'approve'])->name('participants.careplan.approve');
        Route::post('/{carePlan}/new-version', [CarePlanController::class, 'newVersion'])->name('participants.careplan.new-version');
        // W4-5: 42 CFR §460.104(d) participant participation acknowledgment
        Route::patch('/{carePlan}/participation', [CarePlanController::class, 'updateParticipation'])->name('participants.careplan.participation');
    });

    // ─── Phase 4: Alerts ──────────────────────────────────────────────────────
    // Must declare /alerts/unread-count BEFORE /alerts/{alert} to avoid route collision
    Route::get('/alerts/unread-count',          [AlertController::class, 'unreadCount'])->name('alerts.unread-count');
    Route::get('/alerts',                       [AlertController::class, 'index'])->name('alerts.index');
    Route::post('/alerts',                      [AlertController::class, 'store'])->name('alerts.store');
    Route::patch('/alerts/{alert}/acknowledge', [AlertController::class, 'acknowledge'])->name('alerts.acknowledge');
    Route::patch('/alerts/{alert}/resolve',     [AlertController::class, 'resolve'])->name('alerts.resolve');

    // ─── Phase 4: SDRs ────────────────────────────────────────────────────────
    Route::get('/sdrs',         [SdrController::class, 'index'])->name('sdrs.index');
    Route::post('/sdrs',        [SdrController::class, 'store'])->name('sdrs.store');
    Route::get('/sdrs/{sdr}',   [SdrController::class, 'show'])->name('sdrs.show');
    Route::patch('/sdrs/{sdr}', [SdrController::class, 'update'])->name('sdrs.update');
    Route::delete('/sdrs/{sdr}',[SdrController::class, 'destroy'])->name('sdrs.destroy');
    // Phase 1 (MVP roadmap): §460.122 denial workflow
    Route::post('/sdrs/{sdr}/deny', [SdrController::class, 'deny'])->name('sdrs.deny');

    // ─── Phase 1 (MVP roadmap): Appeals workflow — 42 CFR §460.122 ───────────
    Route::get ('/appeals',                        [\App\Http\Controllers\AppealController::class, 'index'])->name('appeals.index');
    Route::post('/appeals',                        [\App\Http\Controllers\AppealController::class, 'store'])->name('appeals.store');
    Route::get ('/appeals/{appeal}',               [\App\Http\Controllers\AppealController::class, 'show'])->name('appeals.show');
    Route::post('/appeals/{appeal}/acknowledge',   [\App\Http\Controllers\AppealController::class, 'acknowledge'])->name('appeals.acknowledge');
    Route::post('/appeals/{appeal}/begin-review',  [\App\Http\Controllers\AppealController::class, 'beginReview'])->name('appeals.begin-review');
    Route::post('/appeals/{appeal}/decide',        [\App\Http\Controllers\AppealController::class, 'decide'])->name('appeals.decide');
    Route::post('/appeals/{appeal}/request-external', [\App\Http\Controllers\AppealController::class, 'requestExternal'])->name('appeals.request-external');
    Route::post('/appeals/{appeal}/withdraw',      [\App\Http\Controllers\AppealController::class, 'withdraw'])->name('appeals.withdraw');
    Route::post('/appeals/{appeal}/close',         [\App\Http\Controllers\AppealController::class, 'close'])->name('appeals.close');
    Route::get ('/appeals/{appeal}/acknowledgment.pdf', [\App\Http\Controllers\AppealController::class, 'downloadAckPdf'])->name('appeals.pdf.ack');
    Route::get ('/appeals/{appeal}/decision.pdf',  [\App\Http\Controllers\AppealController::class, 'downloadDecisionPdf'])->name('appeals.pdf.decision');
    Route::get ('/denial-notices/{notice}/download', [\App\Http\Controllers\AppealController::class, 'downloadNoticePdf'])->name('denial-notices.pdf');

    // ─── Phase 4: IDT Meetings ────────────────────────────────────────────────
    Route::get('/idt',                     [IdtMeetingController::class, 'index'])->name('idt.index');
    // W3-2: meetings list page (Meeting Minutes nav item)
    Route::get('/idt/meetings',            [IdtMeetingController::class, 'meetingsList'])->name('idt.meetings.index');
    Route::post('/idt/meetings',           [IdtMeetingController::class, 'store'])->name('idt.meetings.store');
    Route::get('/idt/meetings/{meeting}',  [IdtMeetingController::class, 'show'])->name('idt.meetings.show');
    Route::patch('/idt/meetings/{meeting}',[IdtMeetingController::class, 'update'])->name('idt.meetings.update');
    // Phase R7 — structured attendance check-in for IDT meeting attendees
    Route::post('/idt/meetings/{meeting}/attendance', [IdtMeetingController::class, 'recordAttendance'])->name('idt.meetings.attendance');
    Route::post('/idt/meetings/{meeting}/start',    [IdtMeetingController::class, 'start'])->name('idt.meetings.start');
    Route::post('/idt/meetings/{meeting}/complete', [IdtMeetingController::class, 'complete'])->name('idt.meetings.complete');
    Route::post('/idt/meetings/{meeting}/participants', [IdtMeetingController::class, 'addParticipant'])->name('idt.meetings.participants.add');
    Route::patch('/idt/meetings/{meeting}/participants/{review}', [IdtMeetingController::class, 'updateReview'])->name('idt.meetings.participants.update');
    Route::post('/idt/meetings/{meeting}/participants/{review}/reviewed', [IdtMeetingController::class, 'markReviewed'])->name('idt.meetings.participants.reviewed');

    // ─── Clinical Module Landing Pages ───────────────────────────────────────
    // Cross-participant views powering the Clinical nav items.
    // Notes/Vitals/Assessments/CarePlans are fully implemented.
    // Medications and Orders are live via ClinicalOverviewController (W3-8).

    // ─── Phase 6A: Enrollment & Intake (Kanban pipeline + state machine) ────────
    Route::get('/enrollment/referrals',                         [ReferralController::class, 'index'])->name('enrollment.referrals.index');
    Route::post('/enrollment/referrals',                        [ReferralController::class, 'store'])->name('enrollment.referrals.store');
    Route::get('/enrollment/referrals/{referral}',              [ReferralController::class, 'show'])->name('enrollment.referrals.show');
    Route::put('/enrollment/referrals/{referral}',              [ReferralController::class, 'update'])->name('enrollment.referrals.update');
    Route::post('/enrollment/referrals/{referral}/transition',  [ReferralController::class, 'transition'])->name('enrollment.referrals.transition');
    Route::post('/enrollment/referrals/{referral}/notes',       [ReferralNoteController::class, 'store'])->name('enrollment.referrals.notes.store');
    // Redirect /enrollment → Kanban pipeline
    Route::get('/enrollment', fn () => redirect()->route('enrollment.referrals.index'))->name('enrollment.index');

    Route::prefix('clinical')->group(function () {
        Route::get('/notes',       [ClinicalDashboardController::class, 'notes'])->name('clinical.notes');
        Route::get('/vitals',      [ClinicalDashboardController::class, 'vitals'])->name('clinical.vitals');
        Route::get('/assessments', [ClinicalDashboardController::class, 'assessments'])->name('clinical.assessments');
        Route::get('/care-plans',  [ClinicalDashboardController::class, 'carePlans'])->name('clinical.care-plans');
        Route::get('/medications', [ClinicalOverviewController::class, 'medications'])->name('clinical.medications');
        // W4-7: Real CPOE worklist is at /orders (ClinicalOrderController::worklist).
        // Keep this route as a redirect so any old bookmarks/links still work.
        Route::get('/orders',      fn () => redirect('/orders'))->name('clinical.orders');
    });

    // ─── Transport Module ─────────────────────────────────────────────────────
    // Dashboard + Manifest live (Phase 5B). Add-On queue for all staff.
    // Remaining items (scheduler, map, dispatch) are Phase 5C stubs.

    Route::prefix('transport')->group(function () {
        Route::get('/',              [TransportController::class, 'dashboard'])->name('transport.index');
        // Manifest run-sheet (Phase 5B)
        Route::get('/manifest',      [TransportRequestController::class, 'manifest'])->name('transport.manifest');
        Route::get('/manifest/runs', [TransportRequestController::class, 'runs'])->name('transport.manifest.runs');
        // Add-On queue (Phase 5B) — any dept submits; Transportation Team approves
        // Old nav link /transport/add-ons → redirects to manifest (nav was updated in Phase 7C polish)
        Route::get('/add-ons',                           fn () => redirect('/transport/manifest'))->name('transport.add-ons.index');
        Route::get('/add-ons/pending',                   [TransportRequestController::class, 'pending'])->name('transport.add-ons.pending');
        Route::post('/add-ons',                          [TransportRequestController::class, 'store'])->name('transport.add-ons.store');
        Route::put('/add-ons/{transportRequest}',        [TransportRequestController::class, 'update'])->name('transport.add-ons.update');
        Route::post('/add-ons/{transportRequest}/cancel',[TransportRequestController::class, 'cancel'])->name('transport.add-ons.cancel');
        // ── CAT1: Nostos Transport integration pending — ComingSoonBanner ────────
        Route::get('/scheduler',     fn () => app(ComingSoonController::class)->show('Transport Scheduler', 8, 'transport'))->name('transport.scheduler');
        Route::get('/map',           fn () => app(ComingSoonController::class)->show('Dispatch Map',        8, 'transport'))->name('transport.map');
        Route::get('/cancellations', fn () => app(ComingSoonController::class)->show('Cancellations',       8, 'transport'))->name('transport.cancellations');
        Route::get('/vehicles',      fn () => app(ComingSoonController::class)->show('Vehicles',            8, 'transport'))->name('transport.vehicles');
        Route::get('/vendors',       fn () => app(ComingSoonController::class)->show('Vendors',             8, 'transport'))->name('transport.vendors');
        Route::get('/credentials',   fn () => app(ComingSoonController::class)->show('Driver Credentials',  8, 'transport'))->name('transport.credentials');
        Route::get('/broker',        fn () => app(ComingSoonController::class)->show('Broker Settings',     8, 'transport'))->name('transport.broker');
        Route::get('/calls',         fn () => app(ComingSoonController::class)->show('Courtesy Calls',      8, 'transport'))->name('transport.calls');
    });

    // ─── Phase 5A: Locations ──────────────────────────────────────────────────
    // Managed by Transportation Team. All staff can view (for appointment booking).
    Route::get('/locations',             [LocationController::class, 'index'])->name('locations.index');
    Route::post('/locations',            [LocationController::class, 'store'])->name('locations.store');
    Route::get('/locations/{location}',  [LocationController::class, 'show'])->name('locations.show');
    Route::put('/locations/{location}',  [LocationController::class, 'update'])->name('locations.update');
    Route::delete('/locations/{location}',[LocationController::class, 'destroy'])->name('locations.destroy');

    // ─── Phase 5A: Schedule Calendar Page ────────────────────────────────────
    Route::get('/schedule',                  [AppointmentController::class, 'calendarPage'])->name('schedule.index');
    Route::get('/schedule/appointments',     [AppointmentController::class, 'calendarAppointments'])->name('schedule.appointments');

    // ─── Phase 5A: Appointments (nested under participant) ────────────────────
    Route::prefix('participants/{participant}')->group(function () {
        Route::get('/appointments',                          [AppointmentController::class, 'index'])->name('participants.appointments.index');
        Route::post('/appointments',                         [AppointmentController::class, 'store'])->name('participants.appointments.store');
        Route::get('/appointments/{appointment}',            [AppointmentController::class, 'show'])->name('participants.appointments.show');
        Route::put('/appointments/{appointment}',            [AppointmentController::class, 'update'])->name('participants.appointments.update');
        Route::patch('/appointments/{appointment}/confirm',  [AppointmentController::class, 'confirm'])->name('participants.appointments.confirm');
        Route::patch('/appointments/{appointment}/complete', [AppointmentController::class, 'complete'])->name('participants.appointments.complete');
        Route::patch('/appointments/{appointment}/cancel',   [AppointmentController::class, 'cancel'])->name('participants.appointments.cancel');
        Route::patch('/appointments/{appointment}/no-show',  [AppointmentController::class, 'noShow'])->name('participants.appointments.no-show');
    });

    // ─── Coming Soon: Phase 4b (scheduling — later) ──────────────────────────
    // Day Center scheduling remains a coming-soon stub.

    Route::prefix('scheduling')->group(function () {
        Route::get('/appointments', fn () => redirect()->route('schedule.index'));
        // W3-2: Day center attendance is now a live page
        Route::get('/day-center',         [DayCenterController::class, 'index'])->name('scheduling.day-center');
        Route::get('/day-center/roster',  [DayCenterController::class, 'roster'])->name('scheduling.day-center.roster');
        Route::post('/day-center/check-in', [DayCenterController::class, 'checkIn'])->name('scheduling.day-center.check-in');
        Route::post('/day-center/absent',   [DayCenterController::class, 'markAbsent'])->name('scheduling.day-center.absent');
        Route::get('/day-center/summary',   [DayCenterController::class, 'summary'])->name('scheduling.day-center.summary');
        // Phase R6 — event-status snapshot + check-out + printable roster
        Route::get('/day-center/event-status', [DayCenterController::class, 'eventStatus'])->name('scheduling.day-center.event_status');
        Route::post('/day-center/check-out',   [DayCenterController::class, 'checkOut'])->name('scheduling.day-center.check-out');
        Route::get('/day-center/roster.pdf',   [DayCenterController::class, 'rosterPdf'])->name('scheduling.day-center.roster_pdf');
        Route::get("/day-center/manage",  [DayCenterScheduleController::class, "index"])->name("scheduling.day-center.manage");
        Route::post("/day-center/manage/bulk", [DayCenterScheduleController::class, "bulkUpdate"])->name("scheduling.day-center.manage.bulk");
    });

    Route::prefix('idt')->group(function () {
        // Legacy redirects — nav hrefs updated in W3-2 but keep these for bookmark compat
        Route::get('/minutes', fn () => redirect('/idt/meetings'))->name('idt.minutes'); // W3-2: now redirects to meetings list
        Route::get('/sdr',     fn () => redirect('/sdrs'))->name('idt.sdr');             // SDR tracker is live at /sdrs
    });

    // ─── W4-1: Grievance Management (42 CFR §460.120–§460.121) ──────────────────
    // Grievance workflow: open → under_review → resolved/escalated/withdrawn.
    // Standard resolution: 30 days. Urgent: 72 hours.
    // NOTE: Static string routes (/overdue, /escalation-staff) MUST be declared
    // before /{grievance} to prevent matching as Eloquent route model binding IDs.
    Route::prefix('grievances')->group(function () {
        Route::get('/',                              [GrievanceController::class, 'index'])->name('grievances.index');
        Route::post('/',                             [GrievanceController::class, 'store'])->name('grievances.store');
        Route::get('/overdue',                       [GrievanceController::class, 'overdue'])->name('grievances.overdue');
        // Returns designation holders for the escalate-to dropdown (QA admin only)
        Route::get('/escalation-staff',              [GrievanceController::class, 'escalationStaff'])->name('grievances.escalation-staff');
        Route::get('/{grievance}',                   [GrievanceController::class, 'show'])->name('grievances.show');
        Route::put('/{grievance}',                   [GrievanceController::class, 'update'])->name('grievances.update');
        Route::post('/{grievance}/start-review',      [GrievanceController::class, 'startReview'])->name('grievances.start-review');
        Route::post('/{grievance}/resolve',          [GrievanceController::class, 'resolve'])->name('grievances.resolve');
        Route::post('/{grievance}/escalate',         [GrievanceController::class, 'escalate'])->name('grievances.escalate');
        Route::post('/{grievance}/withdraw',          [GrievanceController::class, 'withdraw'])->name('grievances.withdraw');
        Route::post('/{grievance}/cms-reportable',    [GrievanceController::class, 'setCmsReportable'])->name('grievances.cms-reportable');
        Route::post('/{grievance}/cms-reported',      [GrievanceController::class, 'markCmsReported'])->name('grievances.cms-reported');
        Route::post('/{grievance}/notify-participant',[GrievanceController::class, 'notifyParticipant'])->name('grievances.notify');
    });

    // ─── W4-1: Participant-Scoped Grievances JSON Endpoint ─────────────────────
    Route::get('/participants/{participant}/grievances', [GrievanceController::class, 'participantGrievances'])->name('participant.grievances');

    // ─── W4-1: Participant Consent Records (HIPAA 45 CFR §164.520) ───────────
    // Tracks NPP acknowledgment and other consent forms per participant.
    // Accessible via the Consents tab in Participants/Show.tsx.
    Route::prefix('participants/{participant}/consents')->group(function () {
        Route::get('/',    [ConsentController::class, 'index'])->name('participant.consents.index');
        Route::post('/',   [ConsentController::class, 'store'])->name('participant.consents.store');
        Route::put('/{consent}', [ConsentController::class, 'update'])->name('participant.consents.update');
    });

    // ─── Phase 6B: QA / Compliance Dashboard + Incidents ─────────────────────
    // Dashboard page (Inertia) + lazy-load compliance detail endpoints (JSON).
    // Incident CRUD is nested under /qa/incidents (not participant) so QA can
    // view the full tenant incident queue without being in a participant context.
    Route::prefix('qa')->group(function () {
        // Dashboard page — pre-loads 6 KPIs; compliance detail loaded lazily
        Route::get('/dashboard',                             [QaDashboardController::class, 'dashboard'])->name('qa.dashboard');
        // Incident CRUD
        Route::get('/incidents',                             [IncidentController::class, 'index'])->name('qa.incidents.index');
        Route::post('/incidents',                            [IncidentController::class, 'store'])->name('qa.incidents.store');
        Route::get('/incidents/{incident}',                  [IncidentController::class, 'show'])->name('qa.incidents.show');
        Route::put('/incidents/{incident}',                  [IncidentController::class, 'update'])->name('qa.incidents.update');
        // Incident lifecycle transitions
        Route::post('/incidents/{incident}/rca',             [IncidentController::class, 'rca'])->name('qa.incidents.rca');
        Route::post('/incidents/{incident}/close',           [IncidentController::class, 'close'])->name('qa.incidents.close');
        // Phase B3 (MVP completion roadmap): sentinel-event classification
        Route::post('/incidents/{incident}/classify-sentinel', [IncidentController::class, 'classifySentinel'])->name('qa.incidents.classify_sentinel');
        // Compliance detail endpoints (lazy-loaded by compliance tabs)
        Route::get('/compliance/unsigned-notes',             [QaDashboardController::class, 'unsignedNotes'])->name('qa.compliance.unsigned-notes');
        Route::get('/compliance/overdue-assessments',        [QaDashboardController::class, 'overdueAssessments'])->name('qa.compliance.overdue-assessments');
        // CSV export — type param: incidents|unsigned_notes|overdue_assessments
        Route::get('/reports/export',                        [QaDashboardController::class, 'exportCsv'])->name('qa.reports.export');
    });

    // ─── W4-6: QAPI Project Tracking ─────────────────────────────────────────
    // 42 CFR §460.136–§460.140: PACE QAPI program — at least 2 active QI projects.
    Route::prefix('qapi')->group(function () {
        Route::get('/projects',                          [QapiController::class, 'index'])->name('qapi.projects.index');
        Route::post('/projects',                         [QapiController::class, 'store'])->name('qapi.projects.store');
        Route::get('/projects/{id}',                     [QapiController::class, 'show'])->name('qapi.projects.show');
        Route::patch('/projects/{id}',                   [QapiController::class, 'update'])->name('qapi.projects.update');
        Route::post('/projects/{id}/remeasure',          [QapiController::class, 'remeasure'])->name('qapi.projects.remeasure');

        // Phase 2 (MVP roadmap): §460.200 annual QAPI evaluation artifact
        Route::get ('/evaluations',                      [\App\Http\Controllers\QapiAnnualEvaluationController::class, 'index'])->name('qapi.evaluations.index');
        Route::post('/evaluations',                      [\App\Http\Controllers\QapiAnnualEvaluationController::class, 'store'])->name('qapi.evaluations.store');
        Route::post('/evaluations/{evaluation}/review',  [\App\Http\Controllers\QapiAnnualEvaluationController::class, 'recordReview'])->name('qapi.evaluations.review');
        Route::get ('/evaluations/{evaluation}/download',[\App\Http\Controllers\QapiAnnualEvaluationController::class, 'download'])->name('qapi.evaluations.download');
    });

    // ─── Finance / Billing (Phase 6C) ────────────────────────────────────────
    // Finance dashboard (Inertia) and REST API for capitation, encounters, auths.

    Route::prefix('finance')->group(function () {
        // Dashboard page
        Route::get('/dashboard',         [FinanceDashboardController::class, 'dashboard'])->name('finance.dashboard');
        Route::get('/reports/export',    [FinanceDashboardController::class, 'exportCsv'])->name('finance.export');

        // Capitation records
        Route::get('/capitation',        [FinanceController::class, 'capitationIndex'])->name('finance.capitation.index');
        Route::post('/capitation',       [FinanceController::class, 'capitationStore'])->name('finance.capitation.store');

        // Encounter log
        Route::get('/encounters',        [FinanceController::class, 'encounterIndex'])->name('finance.encounters.index');
        Route::post('/encounters',       [FinanceController::class, 'encounterStore'])->name('finance.encounters.store');

        // Authorizations
        Route::get('/authorizations',              [FinanceController::class, 'authIndex'])->name('finance.authorizations.index');
        Route::post('/authorizations',             [FinanceController::class, 'authStore'])->name('finance.authorizations.store');
        Route::put('/authorizations/{authorization}', [FinanceController::class, 'authUpdate'])->name('finance.authorizations.update');

        // W5-3: 835 Remittance Processing
        Route::post('/remittance/upload',                              [RemittanceController::class, 'upload'])->name('finance.remittance.upload');
        Route::get('/remittance',                                      [RemittanceController::class, 'index'])->name('finance.remittance.index');
        Route::get('/remittance/{remittanceBatch}',                    [RemittanceController::class, 'show'])->name('finance.remittance.show');
        Route::get('/remittance/{remittanceBatch}/claims',             [RemittanceController::class, 'claims'])->name('finance.remittance.claims');

        // W5-3: Denial Management (42 CFR §405.942 — 120-day appeal deadline)
        Route::get('/denials',                                         [DenialController::class, 'index'])->name('finance.denials.index');
        Route::get('/denials/{denialRecord}',                          [DenialController::class, 'show'])->name('finance.denials.show');
        Route::patch('/denials/{denialRecord}',                        [DenialController::class, 'update'])->name('finance.denials.update');
        Route::post('/denials/{denialRecord}/appeal',                  [DenialController::class, 'appeal'])->name('finance.denials.appeal');
        Route::post('/denials/{denialRecord}/write-off',               [DenialController::class, 'writeOff'])->name('finance.denials.write-off');
    });

    // ── Billing Engine (Phase 9B) ─────────────────────────────────────────────
    // Full billing engine: EDI 837P, capitation, PDE, HPMS, HOS-M, revenue integrity
    Route::prefix('billing')->group(function () {
        // Legacy redirects (kept for backward compatibility with nav links)
        Route::get('/', fn () => redirect('/finance/dashboard'))->name('billing.index');

        // Encounter Submission Queue
        Route::get('/encounters',           [BillingEncounterController::class, 'index'])->name('billing.encounters.index');
        Route::post('/encounters/batch',    [BillingEncounterController::class, 'batch'])->name('billing.encounters.batch');
        Route::post('/encounters',          [BillingEncounterController::class, 'store'])->name('billing.encounters.store');
        Route::match(['PUT', 'PATCH'], '/encounters/{encounter}', [BillingEncounterController::class, 'update'])->name('billing.encounters.update');

        // EDI Batches
        Route::get('/batches',              [EdiBatchController::class, 'index'])->name('billing.batches.index');
        Route::get('/batches/{batch}/download', [EdiBatchController::class, 'download'])->name('billing.batches.download');
        Route::post('/batches/{batch}/acknowledge', [EdiBatchController::class, 'acknowledge'])->name('billing.batches.acknowledge');

        // Capitation (Inertia page + JSON data + store + bulk-import)
        Route::get('/capitation',           [CapitationController::class, 'index'])->name('billing.capitation.index');
        Route::get('/capitation/data',      [CapitationController::class, 'data'])->name('billing.capitation.data');
        Route::post('/capitation/bulk-import', [CapitationController::class, 'bulkImport'])->name('billing.capitation.bulk-import');
        Route::post('/capitation',          [CapitationController::class, 'store'])->name('billing.capitation.store');

        // Part D PDE Records
        Route::get('/pde/troop',            [PdeController::class, 'troop'])->name('billing.pde.troop');
        Route::get('/pde',                  [PdeController::class, 'index'])->name('billing.pde.index');
        Route::post('/pde',                 [PdeController::class, 'store'])->name('billing.pde.store');

        // HPMS File Submissions
        Route::get('/hpms',                 [HpmsController::class, 'index'])->name('billing.hpms.index');
        Route::post('/hpms/generate',       [HpmsController::class, 'generate'])->name('billing.hpms.generate');
        Route::get('/hpms/{submission}/download', [HpmsController::class, 'download'])->name('billing.hpms.download');
        Route::patch('/hpms/{submission}/submit', [HpmsController::class, 'markSubmitted'])->name('billing.hpms.submit');

        // HOS-M Annual Surveys
        Route::get('/hos-m',                [HosMSurveyController::class, 'index'])->name('billing.hosm.index');
        Route::post('/hos-m',               [HosMSurveyController::class, 'store'])->name('billing.hosm.store');
        Route::put('/hos-m/{survey}',       [HosMSurveyController::class, 'update'])->name('billing.hosm.update');
        Route::match(['post', 'patch'], '/hos-m/{survey}/submit', [HosMSurveyController::class, 'submit'])->name('billing.hosm.submit');

        // Revenue Integrity Dashboard
        Route::get('/revenue-integrity',        [RevenueIntegrityController::class, 'index'])->name('billing.revenue-integrity.index');
        Route::get('/revenue-integrity/data',   [RevenueIntegrityController::class, 'data'])->name('billing.revenue-integrity.data');

        // Phase 6 (MVP roadmap): CMS enrollment reconciliation (MMR / TRR ingest)
        Route::get ('/reconciliation',                            [\App\Http\Controllers\EnrollmentReconciliationController::class, 'index'])->name('billing.reconciliation.index');
        Route::post('/reconciliation/mmr',                        [\App\Http\Controllers\EnrollmentReconciliationController::class, 'uploadMmr'])->name('billing.reconciliation.mmr.upload');
        Route::get ('/reconciliation/mmr/{file}',                 [\App\Http\Controllers\EnrollmentReconciliationController::class, 'showMmrFile'])->name('billing.reconciliation.mmr.show');
        Route::post('/reconciliation/trr',                        [\App\Http\Controllers\EnrollmentReconciliationController::class, 'uploadTrr'])->name('billing.reconciliation.trr.upload');
        Route::post('/reconciliation/discrepancies/{record}/resolve', [\App\Http\Controllers\EnrollmentReconciliationController::class, 'resolveDiscrepancy'])->name('billing.reconciliation.discrepancies.resolve');

        // Risk Adjustment (HCC RAF Tracking) — Phase 9C
        Route::get('/risk-adjustment',                          [RiskAdjustmentController::class, 'index'])->name('billing.risk-adjustment.index');
        Route::get('/risk-adjustment/data',                     [RiskAdjustmentController::class, 'data'])->name('billing.risk-adjustment.data');
        Route::get('/risk-adjustment/participant/{id}',         [RiskAdjustmentController::class, 'participant'])->name('billing.risk-adjustment.participant');
        Route::post('/risk-adjustment/recalculate/{id}',        [RiskAdjustmentController::class, 'recalculate'])->name('billing.risk-adjustment.recalculate');

        // Billing Compliance Checklist — Phase 9C
        Route::get('/compliance-checklist',                     [BillingComplianceController::class, 'index'])->name('billing.compliance.index');
        Route::get('/compliance-checklist/data',                [BillingComplianceController::class, 'data'])->name('billing.compliance.data');

        // Legacy: Claims stub (now replaced by full billing engine — keep for graceful 404 redirect)
        Route::get('/claims', fn () => redirect('/billing/encounters'))->name('billing.claims');
    });

    // W3-2: Reports landing page (replaces ComingSoon stub)
    Route::get('/reports',                        [ReportsController::class, 'index'])->name('reports.index');
    Route::get('/reports/data',                   [ReportsController::class, 'data'])->name('reports.data');
    Route::get('/reports/export',                 [ReportsController::class, 'export'])->name('reports.export');
    Route::get('/reports/site-transfers',         [ReportsController::class, 'siteTransfers'])->name('reports.site-transfers');
    Route::get('/reports/site-transfers/export',  [ReportsController::class, 'siteTransfersExport'])->name('reports.site-transfers.export');
    Route::get('/audit',   fn () => redirect('/it-admin/audit'))->name('audit.index'); // Live audit trail is at /it-admin/audit

    // Admin prefix — legacy nav stubs; real admin pages are at /it-admin/*
    Route::prefix('admin')->group(function () {
        Route::get('/users',    fn () => redirect('/it-admin/users'))->name('admin.users');     // CAT2: user management is live at /it-admin/users
        Route::get('/locations', [LocationController::class, 'managePage'])->name('admin.locations'); // Locations management Inertia page
        // W3-2: System Settings is now a live page (replaces ComingSoon stub)
        Route::get('/settings',  [SystemSettingsController::class, 'index'])->name('admin.settings');
        Route::put('/settings',  [SystemSettingsController::class, 'update'])->name('admin.settings.update');
    });

    // ─── Phase 7C: Chat ────────────────────────────────────────────────────────
    // Inertia page
    Route::get('/chat', [ChatController::class, 'index'])->name('chat.index');

    // Chat JSON API (all require authenticated session + channel membership)
    Route::prefix('chat')->name('chat.')->group(function () {
        // Must declare /users/search BEFORE /channels to avoid param collision
        Route::get('/users/search',                [ChatController::class, 'searchUsers'])->name('users.search');
        Route::get('/channels',                    [ChatController::class, 'channels'])->name('channels');
        Route::get('/channels/{channel}/messages', [ChatController::class, 'messages'])->name('messages');
        Route::post('/channels/{channel}/messages',[ChatController::class, 'send'])->name('send');
        Route::post('/channels/{channel}/read',    [ChatController::class, 'markRead'])->name('read');
        Route::post('/direct/{user}',              [ChatController::class, 'directMessage'])->name('direct');
        Route::get('/unread-count',                [ChatController::class, 'unreadCount'])->name('unread');
    });

    // ─── Phase 7C: Profile / Notification Preferences ─────────────────────────
    Route::prefix('profile')->name('profile.')->group(function () {
        Route::get('/notifications',  [ProfileController::class, 'notifications'])->name('notifications');
        Route::match(['PUT', 'PATCH'], '/notifications', [ProfileController::class, 'updateNotifications'])->name('notifications.update');
    });

    // ─── W3-1: User Theme Preference ──────────────────────────────────────────
    // Persists light/dark theme choice server-side. Frontend also writes to
    // localStorage for FOUC prevention between page loads.
    Route::post('/user/theme', [ThemePreferenceController::class, 'update'])->name('user.theme');

    // ─── Phase 10B: Nostos Super Admin Panel ──────────────────────────────────
    // Platform-level tenant management. Only accessible to isSuperAdmin() or isDeptSuperAdmin().
    // NOT for PACE org IT admins — those use /it-admin/* for their own tenant.

    Route::prefix('super-admin-panel')->group(function () {
        Route::get('/',         [SuperAdminPanelController::class, 'index'])->name('super-admin-panel.index');
        Route::get('/tenants',                [SuperAdminPanelController::class, 'tenants'])    ->name('super-admin-panel.tenants');
        Route::get('/tenants/{tenant}',       [SuperAdminPanelController::class, 'tenantShow']) ->name('super-admin-panel.tenants.show');
        Route::patch('/tenants/{tenant}',     [SuperAdminPanelController::class, 'tenantUpdate'])->name('super-admin-panel.tenants.update');
        Route::get('/health',   [SuperAdminPanelController::class, 'health'])->name('super-admin-panel.health');
        Route::post('/onboard', [SuperAdminPanelController::class, 'onboard'])->name('super-admin-panel.onboard');
    });

    // ─── Phase 10B: Site Context Switcher ─────────────────────────────────────
    // Executives and SA dept users can switch active site. Regular users get 403.

    Route::post('/site-context/switch', [SiteContextController::class, 'switch'])->name('site-context.switch');
    Route::delete('/site-context',      [SiteContextController::class, 'clear'])->name('site-context.clear');

    // ─── Tenant Context Switcher (super-admin only) ───────────────────────────
    // Mirrors site-context : sets session('active_tenant_id') so SAs can act
    // inside another organisation's data scope without impersonating. See
    // User::effectiveTenantId() for the consumer side.

    Route::post('/tenant-context/switch', [TenantContextController::class, 'switch'])->name('tenant-context.switch');
    Route::delete('/tenant-context',      [TenantContextController::class, 'clear'])->name('tenant-context.clear');

    // ─── Phase 6D: IT Admin Panel ──────────────────────────────────────────────
    // ── Super Admin Impersonation (super_admin role only) ─────────────────────
    // No dept restriction — enforced via ImpersonationController::requireSuperAdmin().
    // Audit log always records real super-admin's user ID, not the impersonated user.

    Route::prefix('super-admin')->group(function () {
        Route::get('/users',                         [ImpersonationController::class, 'users'])->name('super-admin.users');
        Route::post('/impersonate/{user}',           [ImpersonationController::class, 'start'])->name('super-admin.impersonate');
        Route::delete('/impersonate',                [ImpersonationController::class, 'stop'])->name('super-admin.impersonate.stop');
        Route::post('/view-as',                      [ImpersonationController::class, 'setViewAs'])->name('super-admin.view-as');
        Route::delete('/view-as',                    [ImpersonationController::class, 'clearViewAs'])->name('super-admin.view-as.clear');
    });

    // All routes require department='it_admin' (enforced in controller).
    // Integrations monitor, user management, audit log viewer.

    Route::prefix('it-admin')->group(function () {
        // Integrations monitoring
        Route::get('/integrations',               [IntegrationStatusController::class, 'integrations'])->name('it-admin.integrations');
        Route::get('/integrations/log',           [IntegrationStatusController::class, 'integrationLog'])->name('it-admin.integrations.log');
        Route::post('/integrations/{log}/retry',  [IntegrationStatusController::class, 'retryIntegration'])->name('it-admin.integrations.retry');
        // User management
        Route::get('/users',                        [UserProvisioningController::class, 'users'])->name('it-admin.users');
        Route::post('/users',                       [UserProvisioningController::class, 'provisionUser'])->name('it-admin.users.provision');
        Route::get('/users/{user}/details',         [UserProvisioningController::class, 'userDetails'])->name('it-admin.users.details');
        Route::post('/users/{user}/deactivate',     [UserProvisioningController::class, 'deactivateUser'])->name('it-admin.users.deactivate');
        Route::post('/users/{user}/reactivate',     [UserProvisioningController::class, 'reactivateUser'])->name('it-admin.users.reactivate');
        Route::post('/users/{user}/reset-access',   [UserProvisioningController::class, 'resetAccess'])->name('it-admin.users.reset-access');
        // Designation management — assigns accountability roles for targeted alerting
        Route::patch('/users/{user}/designations',  [UserProvisioningController::class, 'updateDesignations'])->name('it-admin.users.designations');
        // Credentials V1 : job_title + supervisor on existing user
        Route::get  ('/users/role-assignment-options', [UserProvisioningController::class, 'roleAssignmentOptions'])->name('it-admin.users.role-assignment-options');
        Route::patch('/users/{user}/role-assignment', [UserProvisioningController::class, 'updateRoleAssignment'])->name('it-admin.users.role-assignment');

        // Phase 4 (MVP roadmap): Staff credentials + training per §460.64-71
        Route::get ('/users/{user}/credentials',    [\App\Http\Controllers\StaffCredentialController::class, 'index'])->name('it-admin.users.credentials.index');
        // Audit-4 C3 : rate-limit PDF generation (CPU-heavy via dompdf).
        Route::get ('/users/{user}/credentials.pdf',[\App\Http\Controllers\StaffCredentialController::class, 'exportPdf'])
            ->middleware('throttle:20,1')
            ->name('it-admin.users.credentials.pdf');
        Route::post('/users/{user}/credentials',    [\App\Http\Controllers\StaffCredentialController::class, 'storeCredential'])->name('it-admin.users.credentials.store');
        Route::post('/users/{user}/training',       [\App\Http\Controllers\StaffCredentialController::class, 'storeTraining'])->name('it-admin.users.training.store');
        // Audit log viewer
        Route::get('/audit',                      [AuditLogController::class, 'audit'])->name('it-admin.audit');
        Route::get('/audit/log',                  [AuditLogController::class, 'auditLog'])->name('it-admin.audit.log');
        Route::get('/audit/log/{log}',            [AuditLogController::class, 'auditLogShow'])->name('it-admin.audit.log.show');
        Route::get('/audit/export',               [AuditLogController::class, 'exportAuditCsv'])->name('it-admin.audit.export');
        // State Medicaid Configuration — Phase 9C (DEBT-038)
        Route::get('/state-config',               [StateMedicaidConfigController::class, 'index'])->name('it-admin.state-config.index');
        Route::post('/state-config',              [StateMedicaidConfigController::class, 'store'])->name('it-admin.state-config.store');
        Route::put('/state-config/{config}',      [StateMedicaidConfigController::class, 'update'])->name('it-admin.state-config.update');
        Route::delete('/state-config/{config}',   [StateMedicaidConfigController::class, 'destroy'])->name('it-admin.state-config.destroy');
        // Phase 12 (MVP roadmap): Clearinghouse configuration (IT admin only)
        Route::get   ('/clearinghouse-config',              [\App\Http\Controllers\ClearinghouseConfigController::class, 'index'])->name('it-admin.clearinghouse-config.index');
        Route::post  ('/clearinghouse-config',              [\App\Http\Controllers\ClearinghouseConfigController::class, 'store'])->name('it-admin.clearinghouse-config.store');
        Route::put   ('/clearinghouse-config/{config}',     [\App\Http\Controllers\ClearinghouseConfigController::class, 'update'])->name('it-admin.clearinghouse-config.update');
        Route::post  ('/clearinghouse-config/{config}/health-check', [\App\Http\Controllers\ClearinghouseConfigController::class, 'healthCheck'])->name('it-admin.clearinghouse-config.health');
        Route::delete('/clearinghouse-config/{config}',     [\App\Http\Controllers\ClearinghouseConfigController::class, 'destroy'])->name('it-admin.clearinghouse-config.destroy');
        // W4-2: Security & Compliance — BAA tracking + SRA records + encryption status (BLOCKERs 01+03)
        Route::get('/security',                   [SecurityComplianceController::class, 'index'])->name('it-admin.security.index');
        Route::post('/baa',                       [SecurityComplianceController::class, 'baaStore'])->name('it-admin.baa.store');
        Route::put('/baa/{baa}',                  [SecurityComplianceController::class, 'baaUpdate'])->name('it-admin.baa.update');
        Route::post('/sra',                       [SecurityComplianceController::class, 'sraStore'])->name('it-admin.sra.store');
        Route::put('/sra/{sra}',                  [SecurityComplianceController::class, 'sraUpdate'])->name('it-admin.sra.update');
        // W5-1: Break-the-Glass Emergency Access Log
        // Supervisor review of HIPAA emergency access events (45 CFR §164.312(a)(2)(ii)).
        Route::get('/break-glass',                [BreakGlassController::class, 'adminIndex'])->name('it-admin.break-glass.index');
        Route::post('/break-glass/{event}/acknowledge', [BreakGlassController::class, 'acknowledge'])->name('it-admin.break-glass.acknowledge');

        // Phase 4 (MVP roadmap): non-user-scoped staff credential/training actions.
        Route::patch ('/staff-credentials/{credential}', [\App\Http\Controllers\StaffCredentialController::class, 'updateCredential'])->name('it-admin.staff-credentials.update');
        // Audit-4 F3 : trash + restore for accidentally-deleted credentials
        Route::get   ('/users/{user}/credentials/trashed',          [\App\Http\Controllers\StaffCredentialController::class, 'trashedForUser'])->name('it-admin.users.credentials.trashed');
        Route::post  ('/staff-credentials/{credentialId}/restore',  [\App\Http\Controllers\StaffCredentialController::class, 'restoreCredential'])->name('it-admin.staff-credentials.restore');
        Route::post  ('/staff-credentials/{credential}/verify', [\App\Http\Controllers\StaffCredentialController::class, 'verifyCredential'])->name('it-admin.staff-credentials.verify');
        // Audit-4 C3 : rate-limit bulk endpoints (CPU-heavy + side-effect-heavy).
        Route::middleware('throttle:30,1')->group(function () {
            Route::post('/staff-credentials/bulk-renew', [\App\Http\Controllers\StaffCredentialController::class, 'bulkRenew'])->name('it-admin.staff-credentials.bulk-renew');
            Route::post('/staff-credentials/bulk-edit',  [\App\Http\Controllers\StaffCredentialController::class, 'bulkEdit'])->name('it-admin.staff-credentials.bulk-edit');
        });
        Route::delete('/staff-credentials/{credential}', [\App\Http\Controllers\StaffCredentialController::class, 'destroyCredential'])->name('it-admin.staff-credentials.destroy');
        Route::delete('/staff-training/{record}',        [\App\Http\Controllers\StaffCredentialController::class, 'destroyTraining'])->name('it-admin.staff-training.destroy');
    });

    // ─── Phase 2 (MVP roadmap): Compliance audit-pull universes ──────────────
    // CMS / state surveyor-ready JSON (or Inertia page for NF-LOC) exports.
    Route::prefix('compliance')->group(function () {
        Route::get('/nf-loc-status',   [\App\Http\Controllers\ComplianceController::class, 'nfLocStatus'])->name('compliance.nf-loc-status');
        Route::get('/denial-notices',  [\App\Http\Controllers\ComplianceController::class, 'denialNotices'])->name('compliance.denial-notices');
        Route::get('/appeals',         [\App\Http\Controllers\ComplianceController::class, 'appeals'])->name('compliance.appeals');
        Route::get('/sdr-sla',         [\App\Http\Controllers\ComplianceController::class, 'sdrSla'])->name('compliance.sdr-sla');

        // Phase 3 (MVP roadmap): CMS PACE Level I / Level II quarterly reporting
        Route::get ('/level-ii-reporting',                          [\App\Http\Controllers\LevelIiReportingController::class, 'index'])->name('compliance.level-ii-reporting.index');
        Route::post('/level-ii-reporting',                          [\App\Http\Controllers\LevelIiReportingController::class, 'store'])->name('compliance.level-ii-reporting.store');
        Route::post('/level-ii-reporting/{submission}/mark-submitted',[\App\Http\Controllers\LevelIiReportingController::class, 'markSubmitted'])->name('compliance.level-ii-reporting.mark-submitted');
        Route::get ('/level-ii-reporting/{submission}/download',    [\App\Http\Controllers\LevelIiReportingController::class, 'download'])->name('compliance.level-ii-reporting.download');

        // Phase 4 (MVP roadmap): Personnel credentials audit universe (§460.64-71)
        Route::get('/personnel-credentials', [\App\Http\Controllers\ComplianceController::class, 'personnelCredentials'])->name('compliance.personnel-credentials');

        // Phase B1 (MVP completion roadmap): restraints audit universe (42 CFR §460 / CMS PACE Audit)
        Route::get('/restraints', [\App\Http\Controllers\ComplianceController::class, 'restraints'])->name('compliance.restraints');
        // Phase B2 (MVP completion roadmap): infection surveillance audit universe
        Route::get('/infections', [\App\Http\Controllers\ComplianceController::class, 'infections'])->name('compliance.infections');
        // Phase B3 (MVP completion roadmap): sentinel events audit universe
        Route::get('/sentinel-events', [\App\Http\Controllers\ComplianceController::class, 'sentinelEvents'])->name('compliance.sentinel_events');
        // Phase B8b (MVP completion roadmap): ROI requests audit universe
        Route::get('/roi', [\App\Http\Controllers\ComplianceController::class, 'roi'])->name('compliance.roi');
        // Phase C2a (MVP completion roadmap): TB screening audit universe
        Route::get('/tb-screening', [\App\Http\Controllers\ComplianceController::class, 'tbScreening'])->name('compliance.tb_screening');
        // Phase I1 (launch-readiness roadmap): ADE reporting universe — closes C5 scope miss
        Route::get('/ade-reporting', [\App\Http\Controllers\ComplianceController::class, 'ade'])->name('compliance.ade_reporting');
        // Phase P11 — Reportable infectious disease CSV (manual state DPH upload)
        Route::get('/reportable-infections.csv', [\App\Http\Controllers\ComplianceController::class, 'reportableInfectionsCsv'])->name('compliance.reportable_infections.csv');
    });
});

// ─── Phase E1 — Participant portal (public; X-Portal-User-Id header auth) ───
// No `auth` middleware — portal users are separate from staff users.
// In production this would use a session-backed OTP guard; MVP uses a simple
// header-based bearer check resolved inside the controller.
// Phase I4 — session-backed auth with rate limiting. Header auth retained
// during transition window (config: services.portal.allow_header_auth).
Route::get ('/portal/login',         [\App\Http\Controllers\ParticipantPortalController::class, 'loginPage'])->name('portal.login_page');
Route::post('/portal/login',         [\App\Http\Controllers\ParticipantPortalController::class, 'login'])->name('portal.login');
Route::post('/portal/logout',        [\App\Http\Controllers\ParticipantPortalController::class, 'logout'])->name('portal.logout');
// Phase L1 — OTP
Route::post('/portal/otp/send',      [\App\Http\Controllers\ParticipantPortalController::class, 'otpSend'])->name('portal.otp.send');
Route::post('/portal/otp/verify',    [\App\Http\Controllers\ParticipantPortalController::class, 'otpVerify'])->name('portal.otp.verify');

// Phase O3 — /portal/requests GET renders the Inertia page;
// the existing /portal/requests POST still persists the request row.
Route::get('/portal/requests',       [\App\Http\Controllers\ParticipantPortalController::class, 'requestsIndex'])->name('portal.requests.index');
// Phase O3 deleted: /portal/home, /portal/meds, /portal/allergies-ui,
// /portal/problems-ui, /portal/appts, /portal/mail, /portal/reqs.
// Canonical URLs for the portal are now the JSON endpoints above — each
// controller method dual-serves Inertia HTML + JSON via wantsJson() branch.

// Phase L2 — PWA manifest (served via Laravel so test suite covers it)
Route::get('/manifest.webmanifest', function () {
    $path = public_path('manifest.webmanifest');
    if (! is_file($path)) abort(404);
    return response(file_get_contents($path), 200, [
        'Content-Type' => 'application/manifest+json',
    ]);
});
Route::get('/sw.js', function () {
    $path = public_path('sw.js');
    if (! is_file($path)) abort(404);
    return response(file_get_contents($path), 200, [
        'Content-Type'  => 'application/javascript',
        'Cache-Control' => 'no-cache',
    ]);
});
Route::get ('/portal/overview',      [\App\Http\Controllers\ParticipantPortalController::class, 'overview'])->name('portal.overview');
Route::get ('/portal/medications',   [\App\Http\Controllers\ParticipantPortalController::class, 'medications'])->name('portal.medications');
Route::get ('/portal/allergies',     [\App\Http\Controllers\ParticipantPortalController::class, 'allergies'])->name('portal.allergies');
Route::get ('/portal/problems',      [\App\Http\Controllers\ParticipantPortalController::class, 'problems'])->name('portal.problems');
Route::get ('/portal/appointments',  [\App\Http\Controllers\ParticipantPortalController::class, 'appointments'])->name('portal.appointments');
Route::get ('/portal/messages',      [\App\Http\Controllers\ParticipantPortalController::class, 'messagesIndex'])->name('portal.messages.index');
Route::post('/portal/messages',      [\App\Http\Controllers\ParticipantPortalController::class, 'messagesStore'])->name('portal.messages.store');
Route::post('/portal/requests',      [\App\Http\Controllers\ParticipantPortalController::class, 'requestsStore'])->name('portal.requests.store');

// ─── FHIR R4 API (public — Bearer token authenticated) ───────────────────────
// No 'auth' session middleware: external systems authenticate via emr_api_tokens.
// Security is enforced by FhirAuthMiddleware (SHA-256 Bearer token + scope check).
// All reads are logged to audit_log with source_type='fhir_api'.
// Cross-tenant access returns 404 per FHIR conventions (not 403).

// Phase 11 (MVP roadmap): unauthenticated discovery + SMART OAuth endpoints
Route::get ('/fhir/R4/metadata',                   [\App\Http\Controllers\FhirMetadataController::class, 'capabilityStatement'])->name('fhir.metadata');
Route::get ('/fhir/R4/.well-known/smart-configuration', [\App\Http\Controllers\FhirMetadataController::class, 'smartConfiguration'])->name('fhir.smart_config');
Route::get ('/fhir/R4/docs',                       fn () => view('fhir.docs'))->name('fhir.docs');
Route::get ('/fhir/R4/auth/authorize',             [\App\Http\Controllers\SmartOAuthController::class, 'authorize'])->name('fhir.oauth.authorize');
Route::post('/fhir/R4/auth/token',                 [\App\Http\Controllers\SmartOAuthController::class, 'token'])->name('fhir.oauth.token');
Route::post('/fhir/R4/auth/introspect',            [\App\Http\Controllers\SmartOAuthController::class, 'introspect'])->name('fhir.oauth.introspect');
Route::post('/fhir/R4/auth/revoke',                [\App\Http\Controllers\SmartOAuthController::class, 'revoke'])->name('fhir.oauth.revoke');

// Phase 15.2 — SAML SP endpoints (public; scaffold).
Route::get ('/saml/{tenantId}/metadata', [\App\Http\Controllers\SamlController::class, 'metadata'])->name('saml.metadata');
Route::get ('/saml/{tenantId}/login',    [\App\Http\Controllers\SamlController::class, 'login'])->name('saml.login');
Route::post('/saml/{tenantId}/acs',      [\App\Http\Controllers\SamlController::class, 'acs'])->withoutMiddleware(['web'])->name('saml.acs');
Route::get ('/saml/{tenantId}/slo',      [\App\Http\Controllers\SamlController::class, 'slo'])->name('saml.slo');

// Phase 15.7 — HRIS webhook receiver (public; vendor signatures verified inline).
Route::post('/webhooks/hris/{tenantId}/{provider}',
    [\App\Http\Controllers\HrisWebhookController::class, 'receive'])
    ->withoutMiddleware(['web'])
    ->name('hris.webhook');

// Phase 15.1 (MVP roadmap): FHIR Bulk Data Access ($export)
// Using URL-encoded $ so route matches exactly what FHIR Bulk Data clients send.
Route::post  ('/fhir/R4/$export',                             [\App\Http\Controllers\FhirBulkExportController::class, 'export'])->middleware('fhir.auth')->name('fhir.bulk.export');
Route::get   ('/fhir/R4/export-status/{jobId}',               [\App\Http\Controllers\FhirBulkExportController::class, 'status'])->middleware('fhir.auth')->name('fhir.bulk.status');
Route::delete('/fhir/R4/export-status/{jobId}',               [\App\Http\Controllers\FhirBulkExportController::class, 'cancel'])->middleware('fhir.auth')->name('fhir.bulk.cancel');
Route::get   ('/fhir/R4/export-file/{jobId}/{resourceFile}',  [\App\Http\Controllers\FhirBulkExportController::class, 'file'])->middleware('fhir.auth')->where('resourceFile', '.*\.ndjson')->name('fhir.bulk.file');

Route::prefix('fhir/R4')
    ->middleware(['fhir.auth'])
    ->group(function () {
        // Patient resource
        Route::get('/Patient/{id}', [FhirController::class, 'patient'])
            ->middleware('fhir.auth:patient.read')
            ->name('fhir.patient');

        // Observation (vitals) — search by patient
        Route::get('/Observation', [FhirController::class, 'observations'])
            ->middleware('fhir.auth:observation.read')
            ->name('fhir.observations');

        // MedicationRequest — search by patient
        Route::get('/MedicationRequest', [FhirController::class, 'medicationRequests'])
            ->middleware('fhir.auth:medication.read')
            ->name('fhir.medication_requests');

        // Condition (problem list) — search by patient
        Route::get('/Condition', [FhirController::class, 'conditions'])
            ->middleware('fhir.auth:condition.read')
            ->name('fhir.conditions');

        // AllergyIntolerance — search by patient
        Route::get('/AllergyIntolerance', [FhirController::class, 'allergyIntolerances'])
            ->middleware('fhir.auth:allergy.read')
            ->name('fhir.allergy_intolerances');

        // CarePlan — search by patient
        Route::get('/CarePlan', [FhirController::class, 'carePlans'])
            ->middleware('fhir.auth:careplan.read')
            ->name('fhir.care_plans');

        // Appointment — search by patient
        Route::get('/Appointment', [FhirController::class, 'appointments'])
            ->middleware('fhir.auth:appointment.read')
            ->name('fhir.appointments');

        // Immunization — search by patient (Phase 11B)
        Route::get('/Immunization', [FhirController::class, 'immunizations'])
            ->middleware('fhir.auth:immunization.read')
            ->name('fhir.immunizations');

        // Procedure — search by patient (Phase 11B)
        Route::get('/Procedure', [FhirController::class, 'procedures'])
            ->middleware('fhir.auth:procedure.read')
            ->name('fhir.procedures');

        // Observation (SDOH/social-history category) — search by patient + category=social-history (Phase 11B)
        // Note: reuses observation.read scope; category param distinguishes vitals vs SDOH in FhirController
        Route::get('/Observation/social-history', [FhirController::class, 'sdohObservations'])
            ->middleware('fhir.auth:observation.read')
            ->name('fhir.sdoh_observations');

        // Encounter — search by patient (W4-9 GAP-13)
        Route::get('/Encounter', [FhirController::class, 'encounters'])
            ->middleware('fhir.auth:encounter.read')
            ->name('fhir.encounters');

        // DiagnosticReport — search by patient (W4-9 GAP-13)
        // Source: emr_integration_log rows with connector_type='lab_results'
        Route::get('/DiagnosticReport', [FhirController::class, 'diagnosticReports'])
            ->middleware('fhir.auth:diagnosticreport.read')
            ->name('fhir.diagnostic_reports');

        // Practitioner — by ID or name search (W4-9 GAP-13)
        // Only clinical department users are exposed; non-clinical return 404
        Route::get('/Practitioner/{id}', [FhirController::class, 'practitioner'])
            ->middleware('fhir.auth:practitioner.read')
            ->name('fhir.practitioner');
        Route::get('/Practitioner', [FhirController::class, 'practitioners'])
            ->middleware('fhir.auth:practitioner.read')
            ->name('fhir.practitioners');

        // Organization — tenant + sites (W4-9 GAP-13)
        // GET /Organization       → all (tenant + all sites)
        // GET /Organization/{id}  → single (id is prefixed: tenant-1, site-3)
        Route::get('/Organization/{id}', [FhirController::class, 'organization'])
            ->middleware('fhir.auth:organization.read')
            ->name('fhir.organization');
        Route::get('/Organization', [FhirController::class, 'organizations'])
            ->middleware('fhir.auth:organization.read')
            ->name('fhir.organizations');
    });

// ─── Transport Webhooks (public — HMAC-authenticated) ────────────────────────
// No 'auth' middleware: the transport app sends server-to-server with no session.
// Security is enforced by HMAC-SHA256 in WebhookController::transportStatus().
Route::post('/integrations/transport/status-webhook', [WebhookController::class, 'transportStatus'])
    ->name('integrations.transport.webhook');

// ─── Phase 6D: Inbound Integration Endpoints (public — tenant-header auth) ───
// No 'auth' session middleware: external systems (hospitals, labs) send server-to-server.
// Tenant resolved from X-Integration-Tenant header. Processing is async via queued jobs.
Route::prefix('integrations')->group(function () {
    Route::post('/hl7/adt',    [IntegrationController::class, 'adtMessage'])->name('integrations.hl7.adt');
    Route::post('/labs/result',[IntegrationController::class, 'labResult'])->name('integrations.labs.result');
});

// ─── Health Check ─────────────────────────────────────────────────────────────
Route::get('/up', fn () => response()->json(['status' => 'ok']))->name('health');

// ─── Phase W2 — Test-only routes for axios interceptor + Toaster wiring ──────
// Gated to local + testing envs so production never exposes them. Lets a
// Feature test fire a deliberate 5xx/4xx and assert the JSON shape the
// frontend axios interceptor relies on (so a future renaming of detail.message
// or status-code behavior would surface red instead of silent in production).
if (app()->environment(['testing', 'local'])) {
    Route::get('/__test/__500', fn () => response()->json(['message' => 'Deliberate 500 for Toaster test'], 500))->name('test_only.500');
    Route::get('/__test/__403', fn () => response()->json(['message' => 'Deliberate 403 for Toaster test'], 403))->name('test_only.403');
    Route::get('/__test/__409', fn () => response()->json(['message' => 'Deliberate 409 for Toaster test'], 409))->name('test_only.409');
    Route::get('/__test/__422', fn () => response()->json(['message' => 'invalid', 'errors' => ['field' => ['required']]], 422))->name('test_only.422');
}
