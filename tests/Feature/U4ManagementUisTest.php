<?php

// ─── Phase U4 — DME + ContractedProvider management UIs are wired ──────────
namespace Tests\Feature;

use Tests\TestCase;

class U4ManagementUisTest extends TestCase
{
    public function test_dme_vue_has_register_issue_return_buttons(): void
    {
        $vue = file_get_contents(resource_path('js/Pages/Network/Dme.vue'));
        $this->assertStringContainsString('data-testid="dme-add-toggle"', $vue);
        $this->assertStringContainsString('data-testid="dme-add-form"', $vue);
        $this->assertStringContainsString('data-testid="dme-issue-btn"', $vue);
        $this->assertStringContainsString('data-testid="dme-issue-form"', $vue);
        $this->assertStringContainsString('data-testid="dme-return-btn"', $vue);
        // Each handler hits the right route.
        $this->assertStringContainsString("axios.post('/network/dme'", $vue);
        $this->assertStringContainsString('/network/dme/${issueItemId.value}/issue', $vue);
        $this->assertStringContainsString('/network/dme/issuances/${issuanceId}/return', $vue);
        // Participant search route is correct (not /api/participants/search).
        $this->assertStringContainsString("axios.get('/participants/search'", $vue);
        $this->assertStringNotContainsString("'/api/participants/search'", $vue);
    }

    public function test_contracted_providers_vue_has_add_buttons(): void
    {
        $vue = file_get_contents(resource_path('js/Pages/Network/ContractedProviders.vue'));
        $this->assertStringContainsString('data-testid="cp-add-toggle"', $vue);
        $this->assertStringContainsString('data-testid="cp-add-form"', $vue);
        $this->assertStringContainsString('data-testid="cp-add-contract-btn"', $vue);
        $this->assertStringContainsString('data-testid="cp-add-contract-form"', $vue);
        $this->assertStringContainsString("axios.post('/network/contracted-providers'", $vue);
        $this->assertStringContainsString('/network/contracted-providers/${contractProviderId.value}/contracts', $vue);
    }
}
