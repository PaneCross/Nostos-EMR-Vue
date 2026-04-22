<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ─── Phase 15.1 (MVP roadmap) — FHIR Bulk Data Access ($export) ─────────────
// HL7 FHIR Bulk Data Access IG v2.0.0 compliant. Stores an async export
// job tracking row per request. The actual NDJSON files live on the
// Laravel filesystem at storage/app/fhir-exports/{job_id}/{resource}.ndjson.
//
// Lifecycle:
//   accepted → in_progress → complete (or failed | cancelled)
//
// Tenant-scoped. No cross-tenant visibility even with system/*.read scope.
// ─────────────────────────────────────────────────────────────────────────────

return new class extends Migration {
    public function up(): void
    {
        Schema::create('emr_fhir_bulk_export_jobs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained('shared_tenants')->cascadeOnDelete();
            $t->foreignId('api_token_id')->nullable()->constrained('emr_api_tokens')->nullOnDelete();
            $t->string('status', 20)->default('accepted'); // accepted|in_progress|complete|failed|cancelled
            $t->text('resource_types')->nullable(); // pipe-separated subset, or null = all
            $t->timestamp('since')->nullable();
            $t->string('output_format', 60)->default('application/fhir+ndjson');
            $t->integer('progress_pct')->default(0);
            $t->text('manifest_json')->nullable(); // populated on complete
            $t->text('error_message')->nullable();
            $t->timestamp('started_at')->nullable();
            $t->timestamp('completed_at')->nullable();
            $t->timestamps();

            $t->index(['tenant_id', 'status'], 'emr_bulk_export_tenant_status_idx');
        });

        \DB::statement("
            ALTER TABLE emr_fhir_bulk_export_jobs
            ADD CONSTRAINT emr_bulk_export_status_check
            CHECK (status IN ('accepted','in_progress','complete','failed','cancelled'))
        ");
    }

    public function down(): void
    {
        Schema::dropIfExists('emr_fhir_bulk_export_jobs');
    }
};
