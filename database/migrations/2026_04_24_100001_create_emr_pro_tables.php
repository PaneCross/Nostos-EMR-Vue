<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Survey definition (shared + tenant — tenant=null = system-shipped).
        Schema::create('emr_pro_surveys', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->nullable()->constrained('shared_tenants')->cascadeOnDelete();
            $t->string('key', 40);           // mood_weekly | pain_weekly | function_weekly
            $t->string('title', 200);
            $t->jsonb('questions');          // [{id,text,type,min,max,options}]
            $t->string('cadence', 20)->default('weekly'); // weekly | biweekly | monthly
            $t->timestamps();

            $t->unique(['tenant_id', 'key'], 'pro_surveys_uniq');
        });

        Schema::create('emr_pro_survey_schedules', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained('shared_tenants')->cascadeOnDelete();
            $t->foreignId('participant_id')->constrained('emr_participants')->cascadeOnDelete();
            $t->foreignId('survey_id')->constrained('emr_pro_surveys')->cascadeOnDelete();
            $t->timestamp('next_send_at');
            $t->timestamp('last_sent_at')->nullable();
            $t->boolean('is_active')->default(true);
            $t->timestamps();

            $t->index(['tenant_id', 'next_send_at', 'is_active'], 'pro_sched_queue_idx');
        });

        Schema::create('emr_pro_responses', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained('shared_tenants')->cascadeOnDelete();
            $t->foreignId('participant_id')->constrained('emr_participants')->cascadeOnDelete();
            $t->foreignId('survey_id')->constrained('emr_pro_surveys')->cascadeOnDelete();
            $t->jsonb('answers');            // { "q1": 7, "q2": "moderate" }
            $t->integer('aggregate_score')->nullable();
            $t->timestamp('received_at');
            $t->string('delivery_channel', 20)->nullable(); // sms | portal | phone
            $t->timestamps();

            $t->index(['tenant_id', 'participant_id', 'received_at'], 'pro_responses_trend_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('emr_pro_responses');
        Schema::dropIfExists('emr_pro_survey_schedules');
        Schema::dropIfExists('emr_pro_surveys');
    }
};
