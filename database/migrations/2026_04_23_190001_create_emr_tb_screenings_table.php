<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// ─── Phase C2a — TB screening records (42 CFR §460.71 annual requirement) ───
return new class extends Migration {
    public function up(): void
    {
        Schema::create('emr_tb_screenings', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained('shared_tenants')->cascadeOnDelete();
            $t->foreignId('participant_id')->constrained('emr_participants')->cascadeOnDelete();
            $t->foreignId('recorded_by_user_id')->nullable()->constrained('shared_users')->nullOnDelete();
            $t->string('screening_type', 30);   // ppd|quantiferon|t_spot|chest_xray|symptom_only
            $t->date('performed_date');
            $t->string('result', 20);           // positive|negative|indeterminate
            $t->decimal('induration_mm', 5, 1)->nullable();  // PPD only
            $t->text('follow_up_text')->nullable();
            $t->date('next_due_date');          // auto-set to performed_date + 1 year
            $t->text('notes')->nullable();
            $t->timestamps();

            $t->index(['tenant_id', 'participant_id', 'performed_date'], 'tb_screen_trend_idx');
            $t->index(['tenant_id', 'next_due_date'], 'tb_screen_due_idx');
        });

        DB::statement("ALTER TABLE emr_tb_screenings ADD CONSTRAINT emr_tb_type_chk
            CHECK (screening_type IN ('ppd','quantiferon','t_spot','chest_xray','symptom_only'))");
        DB::statement("ALTER TABLE emr_tb_screenings ADD CONSTRAINT emr_tb_result_chk
            CHECK (result IN ('positive','negative','indeterminate'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('emr_tb_screenings');
    }
};
