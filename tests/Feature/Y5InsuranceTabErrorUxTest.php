<?php

// ─── Phase Y5 — InsuranceTab error-UX hardening (Audit-13 H2/H3)
// Asserts the source contains the field-level 422 extraction + the visible
// spend-down load-error path. Pairs with V5's behavioral Toaster test —
// this test is structural so a future refactor can't silently regress.
namespace Tests\Feature;

use Tests\TestCase;

class Y5InsuranceTabErrorUxTest extends TestCase
{
    private string $source;

    protected function setUp(): void
    {
        parent::setUp();
        $this->source = file_get_contents(
            resource_path('js/Pages/Participants/Tabs/InsuranceTab.vue')
        );
    }

    public function test_submit_extracts_field_level_422_errors(): void
    {
        $this->assertStringContainsString(
            'fieldErrors',
            $this->source,
            'submit() must extract response.data.errors{} into a field-level message.'
        );
        $this->assertStringContainsString(
            "e.response?.data?.errors",
            $this->source,
        );
    }

    public function test_load_spend_down_no_longer_silently_swallows_errors(): void
    {
        $this->assertStringNotContainsString(
            "/* silent */",
            $this->source,
            'loadSpendDown must not silent-catch — Audit-13 H3.'
        );
        $this->assertStringContainsString(
            'spendDownLoadError',
            $this->source,
            'loadSpendDown must surface failures via spendDownLoadError ref.'
        );
        $this->assertStringContainsString(
            'role="alert"',
            $this->source,
            'spend-down load error must render in an aria role="alert" region.'
        );
    }
}
