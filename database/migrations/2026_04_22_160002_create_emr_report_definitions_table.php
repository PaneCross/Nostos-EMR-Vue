<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ─── Phase 15.3 — Custom report definitions ─────────────────────────────────
// User-saved report definitions. A definition is {entity, filters, group_by,
// columns} JSON. ReportRunService translates it into a tenant-scoped query.
// ─────────────────────────────────────────────────────────────────────────────

return new class extends Migration {
    public function up(): void
    {
        Schema::create('emr_report_definitions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained('shared_tenants')->cascadeOnDelete();
            $t->foreignId('created_by_user_id')->nullable()->constrained('shared_users')->nullOnDelete();
            $t->string('name', 200);
            $t->string('entity', 60); // participants|medications|grievances|appointments|incidents|care_plans
            $t->jsonb('filters')->nullable();     // array of {field, op, value}
            $t->jsonb('columns')->nullable();     // array of field names
            $t->jsonb('group_by')->nullable();    // array of field names
            $t->boolean('is_shared')->default(false);
            $t->timestamp('last_run_at')->nullable();
            $t->timestamps();

            $t->index(['tenant_id', 'entity'], 'reports_tenant_entity_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('emr_report_definitions');
    }
};
