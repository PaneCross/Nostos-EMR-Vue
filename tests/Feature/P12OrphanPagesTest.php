<?php

// ─── Phase P12 — orphan Vue page sweep ─────────────────────────────────────
// All Vue files in resources/js/Pages/ that the find-orphan-pages.sh script
// surfaces as candidates have been triaged. False positives are documented
// here so the regression doesn't recur.
namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class P12OrphanPagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_orphan_finder_script_is_present(): void
    {
        $this->assertFileExists(base_path('bin/find-orphan-pages.sh'));
    }

    public function test_known_dynamic_registry_pages_exist(): void
    {
        // DiseaseRegistryController interpolates the component name, so the
        // basic grep can't see them. Verify each file exists.
        foreach (['Diabetes', 'Chf', 'Copd', 'RegistryView'] as $name) {
            $this->assertFileExists(resource_path("js/Pages/Registries/{$name}.vue"));
        }
    }

    public function test_known_dynamic_dept_dashboards_exist(): void
    {
        // Dashboard/Index.vue imports each Depts/* file by relative path.
        // Those are alive even if a tenant-wide grep can't link them to a route.
        foreach (['PrimaryCareDashboard', 'TherapiesDashboard', 'DietaryDashboard'] as $name) {
            $this->assertFileExists(resource_path("js/Pages/Dashboard/Depts/{$name}.vue"));
        }
    }

    public function test_error_stub_pages_kept_for_future_wiring(): void
    {
        // Errors/404, /500, /503 are not currently rendered by any controller
        // (only Errors/403 is, via CheckDepartmentAccess middleware). They are
        // kept as stubs so a future Inertia-error-page wiring can drop them in
        // without re-creating the Vue component. Cost: 3 small files.
        foreach (['404', '500', '503', '403'] as $code) {
            $this->assertFileExists(resource_path("js/Pages/Errors/{$code}.vue"));
        }
    }
}
