<?php

// ─── Phase U1 — verify Audit-9 CRITICAL URL fixes resolve to real routes ───
// Audit-9 found 6 buttons in the Vue UI that POSTed to URLs that returned
// 404 (e.g. wrong pluralization, stale resource path, missing nested
// segment). Wave U1 fixed each one. This test asserts every fixed URL now
// resolves to an actual registered Route — pure router-table check, no DB.
// Regression trap: if anyone deletes / renames a route without updating
// the Vue side, this test will catch it before it ships.
namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class U1FixedUrlsTest extends TestCase
{
    public function test_super_admin_panel_route_exists(): void
    {
        $route = Route::getRoutes()->getByName('super_admin_panel.index')
            ?? collect(Route::getRoutes())->first(fn ($r) => str_starts_with($r->uri(), 'super-admin-panel'));
        $this->assertNotNull($route, '/super-admin-panel route must exist (SuperAdminDashboard.vue link target).');
    }

    public function test_finance_denials_route_exists(): void
    {
        $matched = collect(Route::getRoutes())
            ->first(fn ($r) => $r->uri() === 'finance/denials');
        $this->assertNotNull($matched, 'GET /finance/denials must exist (Denials.vue link target).');
    }

    public function test_finance_remittance_route_exists(): void
    {
        $matched = collect(Route::getRoutes())
            ->first(fn ($r) => $r->uri() === 'finance/remittance');
        $this->assertNotNull($matched, 'GET /finance/remittance must exist (Remittance.vue link target).');
    }

    public function test_compliance_checklist_data_route_exists(): void
    {
        $matched = collect(Route::getRoutes())
            ->first(fn ($r) => $r->uri() === 'billing/compliance-checklist/data');
        $this->assertNotNull($matched, 'GET /billing/compliance-checklist/data must exist (ComplianceChecklist.vue runCheck target).');
    }

    public function test_root_route_exists_for_error_page_recovery(): void
    {
        $matched = collect(Route::getRoutes())
            ->first(fn ($r) => $r->uri() === '/' && in_array('GET', $r->methods(), true));
        $this->assertNotNull($matched, "GET / must exist as the error-page 'Go to Dashboard' fallback.");
    }

    public function test_critical_url_fixes_present_in_vue_files(): void
    {
        // Smoke-check the Vue files reference the corrected URLs.
        $superAdmin = file_get_contents(resource_path('js/Pages/Dashboard/Depts/SuperAdminDashboard.vue'));
        $this->assertStringContainsString("/super-admin-panel", $superAdmin);
        $this->assertStringNotContainsString("router.visit('/super-admin')", $superAdmin);

        $denials = file_get_contents(resource_path('js/Pages/Finance/Denials.vue'));
        $this->assertStringContainsString("'/finance/denials'", $denials);
        $this->assertStringNotContainsString("'/billing/denials'", $denials);

        $remittance = file_get_contents(resource_path('js/Pages/Finance/Remittance.vue'));
        $this->assertStringContainsString("'/finance/remittance'", $remittance);
        $this->assertStringNotContainsString("'/billing/remittance'", $remittance);

        foreach (['404', '403', '500'] as $code) {
            $err = file_get_contents(resource_path("js/Pages/Errors/{$code}.vue"));
            $this->assertStringContainsString("router.visit('/')", $err, "Errors/{$code}.vue must redirect to /");
            $this->assertStringNotContainsString("router.visit('/dashboard')", $err);
        }
    }
}
