<?php

// ─── Phase V4 — Per-field 422 error extraction in 4 forms ──────────────────
namespace Tests\Feature;

use Tests\TestCase;

class V4ErrorsExtractionTest extends TestCase
{
    public function test_dme_vue_extracts_per_field_errors(): void
    {
        $vue = file_get_contents(resource_path('js/Pages/Network/Dme.vue'));
        // Both submitAddItem + submitIssue should now do the errors-extraction pattern.
        $this->assertEquals(2, substr_count($vue, '?.response?.data?.errors ?? null'));
        $this->assertStringContainsString('Object.values(errs).flat().join', $vue);
    }

    public function test_breach_incidents_vue_extracts_per_field_errors(): void
    {
        $vue = file_get_contents(resource_path('js/Pages/ItAdmin/BreachIncidents.vue'));
        $this->assertStringContainsString('?.response?.data?.errors ?? null', $vue);
        $this->assertStringContainsString('Object.values(errs).flat().join', $vue);
    }

    public function test_amendment_requests_vue_extracts_per_field_errors(): void
    {
        $vue = file_get_contents(resource_path('js/Pages/Compliance/AmendmentRequests.vue'));
        $this->assertStringContainsString('?.response?.data?.errors ?? null', $vue);
        $this->assertStringContainsString('decision_rationale', $vue);
    }

    public function test_prior_auth_queue_vue_extracts_per_field_errors(): void
    {
        $vue = file_get_contents(resource_path('js/Pages/Pharmacy/PriorAuthQueue.vue'));
        $this->assertStringContainsString('?.response?.data?.errors ?? null', $vue);
        $this->assertStringContainsString('Object.values(errs).flat().join', $vue);
    }
}
