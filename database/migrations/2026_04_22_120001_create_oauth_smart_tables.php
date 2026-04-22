<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ─── Phase 11 (MVP roadmap) — SMART on FHIR OAuth2 tables ────────────────────
// Lightweight OAuth2 / SMART App Launch 2.0 infrastructure that layers on top
// of the existing emr_api_tokens custom Bearer infrastructure. We deliberately
// avoid installing Laravel Passport — it's far heavier than we need and our
// per-tenant tokens are already immutable + scope-checked + audit-logged.
//
// Tables:
//   shared_oauth_clients          — registered OAuth client apps (one row per
//                                   app per tenant). Confidential or public.
//                                   PKCE required for public clients.
//   emr_oauth_authorization_codes — short-lived (60s) authorization codes
//                                   issued after SMART /authorize; exchanged
//                                   at /token for an emr_api_tokens row.
// ─────────────────────────────────────────────────────────────────────────────

return new class extends Migration {
    public function up(): void
    {
        Schema::create('shared_oauth_clients', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained('shared_tenants')->cascadeOnDelete();
            $t->string('client_id', 64)->unique();
            $t->string('client_secret_hash', 64)->nullable(); // null → public client (PKCE required)
            $t->string('name');
            $t->text('redirect_uris')->nullable();            // pipe-separated
            $t->string('client_type', 20)->default('confidential'); // confidential|public
            $t->text('allowed_scopes');                       // pipe-separated SMART scopes
            $t->boolean('is_active')->default(true);
            $t->timestamps();

            $t->index(['tenant_id', 'is_active'], 'oauth_clients_tenant_active_idx');
        });

        \DB::statement("
            ALTER TABLE shared_oauth_clients
            ADD CONSTRAINT oauth_clients_type_check
            CHECK (client_type IN ('confidential','public'))
        ");

        Schema::create('emr_oauth_authorization_codes', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained('shared_tenants')->cascadeOnDelete();
            $t->foreignId('oauth_client_id')->constrained('shared_oauth_clients')->cascadeOnDelete();
            $t->foreignId('user_id')->nullable()->constrained('shared_users')->nullOnDelete();
            $t->foreignId('participant_id')->nullable()->constrained('emr_participants')->nullOnDelete();
            $t->string('code', 128)->unique();
            $t->text('scopes');              // pipe-separated SMART scopes
            $t->string('redirect_uri');
            $t->string('code_challenge', 128)->nullable();  // PKCE
            $t->string('code_challenge_method', 10)->nullable(); // S256 required; plain rejected
            $t->timestamp('expires_at');
            $t->timestamp('used_at')->nullable();
            $t->timestamps();

            $t->index('expires_at', 'oauth_codes_exp_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('emr_oauth_authorization_codes');
        Schema::dropIfExists('shared_oauth_clients');
    }
};
