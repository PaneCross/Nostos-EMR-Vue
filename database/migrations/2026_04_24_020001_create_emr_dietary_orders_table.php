<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('emr_dietary_orders', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained('shared_tenants')->cascadeOnDelete();
            $t->foreignId('participant_id')->constrained('emr_participants')->cascadeOnDelete();
            $t->foreignId('ordered_by_user_id')->nullable()->constrained('shared_users')->nullOnDelete();
            $t->string('diet_type', 30);
            $t->integer('calorie_target')->nullable();
            $t->integer('fluid_restriction_ml_per_day')->nullable();
            $t->string('texture_modification', 40)->nullable(); // regular|mechanical_soft|pureed|nectar_thick|honey_thick
            $t->text('allergen_exclusions')->nullable();
            $t->date('effective_date');
            $t->date('discontinued_date')->nullable();
            $t->text('rationale')->nullable();
            $t->text('notes')->nullable();
            $t->timestamps();

            $t->index(['tenant_id', 'participant_id', 'discontinued_date'], 'dietary_orders_active_idx');
        });

        DB::statement("ALTER TABLE emr_dietary_orders ADD CONSTRAINT emr_dietary_diet_type_chk
            CHECK (diet_type IN ('regular','diabetic','renal','cardiac','low_sodium','pureed','mechanical_soft','npo','other'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('emr_dietary_orders');
    }
};
