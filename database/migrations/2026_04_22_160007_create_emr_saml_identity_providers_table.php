<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ─── Phase 15.2 — SAML SSO scaffolding ──────────────────────────────────────
// Per-tenant SAML 2.0 Identity Provider configuration. Scaffold pattern:
// the endpoints (/saml/login, /saml/acs, /saml/metadata, /saml/slo) exist
// but return "scaffold — install laravel-saml2 or equivalent SP library to
// activate." Real wiring requires a library install + IdP metadata import.
// ─────────────────────────────────────────────────────────────────────────────

return new class extends Migration {
    public function up(): void
    {
        Schema::create('emr_saml_identity_providers', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained('shared_tenants')->cascadeOnDelete();
            $t->string('display_name', 150);
            $t->string('entity_id');             // IdP entityID
            $t->string('sso_url');               // IdP SSO endpoint
            $t->string('slo_url')->nullable();   // IdP Single Logout endpoint
            $t->text('x509_cert');               // IdP signing cert (PEM or base64)
            $t->string('sp_entity_id');          // our SP entityID for this tenant
            $t->string('name_id_format', 100)->default('urn:oasis:names:tc:SAML:1.1:nameid-format:emailAddress');
            $t->jsonb('attribute_mapping')->nullable(); // {email: "email_attr_name", first_name: "...", dept: "..."}
            $t->boolean('is_active')->default(false);
            $t->timestamp('last_login_at')->nullable();
            $t->timestamps();

            $t->unique(['tenant_id', 'entity_id'], 'saml_idp_tenant_entity_uq');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('emr_saml_identity_providers');
    }
};
