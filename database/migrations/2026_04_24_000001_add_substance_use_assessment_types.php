<?php

// ─── Phase J1 follow-up — substance-use assessment types ─────────────────────
// Add audit_c_alcohol / cage_alcohol / dast10_substance to the CHECK constraint
// on emr_assessments.assessment_type. Scoring lives in AssessmentScoringService.
// ─────────────────────────────────────────────────────────────────────────────

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        DB::statement('ALTER TABLE emr_assessments DROP CONSTRAINT IF EXISTS emr_assessments_assessment_type_check');
        DB::statement("
            ALTER TABLE emr_assessments
            ADD CONSTRAINT emr_assessments_assessment_type_check
            CHECK (assessment_type IN (
                'initial_comprehensive', 'adl_functional', 'mmse_cognitive',
                'phq9_depression', 'gad7_anxiety', 'nutritional',
                'fall_risk_morse', 'pain_scale', 'annual_reassessment', 'custom',
                'braden_scale', 'moca_cognitive', 'oral_health',
                'fall_history', 'lace_plus_index',
                'audit_c_alcohol', 'cage_alcohol', 'dast10_substance'
            ))
        ");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE emr_assessments DROP CONSTRAINT IF EXISTS emr_assessments_assessment_type_check');
        DB::statement("
            ALTER TABLE emr_assessments
            ADD CONSTRAINT emr_assessments_assessment_type_check
            CHECK (assessment_type IN (
                'initial_comprehensive', 'adl_functional', 'mmse_cognitive',
                'phq9_depression', 'gad7_anxiety', 'nutritional',
                'fall_risk_morse', 'pain_scale', 'annual_reassessment', 'custom',
                'braden_scale', 'moca_cognitive', 'oral_health',
                'fall_history', 'lace_plus_index'
            ))
        ");
    }
};
