<?php

// ─── Migration: emr_credential_definition_targets ─────────────────────────────
// Targeting rules for credential definitions. OR semantics : a user is targeted
// if ANY of their (department, job_title, or any of their designations) matches
// any row for a given definition_id.
//
// Examples:
//   def 'BLS Certification' → targets [(department, primary_care), (department, nursing), (job_title, ot), (job_title, pt)]
//   def 'RN License'        → targets [(job_title, rn)]
//   def 'DEA Registration'  → targets [(job_title, md), (job_title, np), (designation, prescriber)]
// ─────────────────────────────────────────────────────────────────────────────

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('emr_credential_definition_targets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('credential_definition_id')
                ->constrained('emr_credential_definitions')
                ->cascadeOnDelete();

            $table->string('target_kind', 20);     // department | job_title | designation
            $table->string('target_value', 80);    // dept code, job title code, or designation code

            $table->timestampsTz();

            $table->unique(['credential_definition_id', 'target_kind', 'target_value'], 'cred_def_targets_unique');
            $table->index(['target_kind', 'target_value']);
        });

        DB::statement("
            ALTER TABLE emr_credential_definition_targets
            ADD CONSTRAINT emr_cred_def_targets_kind_check
            CHECK (target_kind IN ('department', 'job_title', 'designation'))
        ");
    }

    public function down(): void
    {
        Schema::dropIfExists('emr_credential_definition_targets');
    }
};
