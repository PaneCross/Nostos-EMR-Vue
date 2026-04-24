<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('emr_care_gaps', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained('shared_tenants')->cascadeOnDelete();
            $t->foreignId('participant_id')->constrained('emr_participants')->cascadeOnDelete();
            $t->string('measure', 40); // annual_pcp_visit|flu_shot|pneumococcal|colonoscopy|mammogram|a1c|diabetic_eye_exam
            $t->boolean('satisfied');
            $t->date('last_satisfied_date')->nullable();
            $t->date('next_due_date')->nullable();
            $t->text('reason_open')->nullable();
            $t->timestamp('calculated_at');
            $t->timestamps();

            $t->unique(['tenant_id', 'participant_id', 'measure'], 'care_gaps_uniq');
            $t->index(['tenant_id', 'measure', 'satisfied'], 'care_gaps_measure_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('emr_care_gaps');
    }
};
