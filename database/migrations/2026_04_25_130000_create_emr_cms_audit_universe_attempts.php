<?php

// Phase R11 — track CMS Audit Protocol 2.0 universe submission attempts.
// CMS limits PACE orgs to 3 universe submission attempts. The 4th attempt
// is logged as non-compliance. We pre-validate before each export and
// surface the count to operators.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('emr_cms_audit_universe_attempts', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('tenant_id');
            $t->string('audit_id', 60);          // CMS audit reference (e.g. PACE-2026-Q2)
            $t->string('universe', 40);          // sdr | grievances | disenrollments | appeals
            $t->unsignedTinyInteger('attempt_number');
            $t->boolean('passed_validation')->default(false);
            $t->jsonb('validation_errors')->nullable();
            $t->unsignedInteger('row_count')->default(0);
            $t->date('period_start');
            $t->date('period_end');
            $t->unsignedBigInteger('exported_by_user_id');
            $t->timestamps();

            $t->foreign('tenant_id')->references('id')->on('shared_tenants')->cascadeOnDelete();
            $t->foreign('exported_by_user_id')->references('id')->on('shared_users')->cascadeOnDelete();
            $t->index(['tenant_id', 'audit_id', 'universe']);
        });

        DB::statement("ALTER TABLE emr_cms_audit_universe_attempts ADD CONSTRAINT cms_audit_universe_check CHECK (universe IN ('sdr','grievances','disenrollments','appeals'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('emr_cms_audit_universe_attempts');
    }
};
