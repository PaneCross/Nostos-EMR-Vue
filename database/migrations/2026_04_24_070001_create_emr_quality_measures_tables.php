<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Reference (non-tenant) HEDIS + CMS Stars measures.
        Schema::create('emr_quality_measures', function (Blueprint $t) {
            $t->id();
            $t->string('measure_id', 30)->unique(); // CDC-HRA01, CBP, EED, etc.
            $t->string('name', 200);
            $t->string('category', 50); // hedis | cms_stars | process | outcome
            $t->text('numerator_definition');
            $t->text('denominator_definition');
            $t->string('data_source', 80)->nullable(); // which tables/fields drive it
            $t->timestamps();
        });

        // Per-tenant nightly snapshot.
        Schema::create('emr_quality_measure_snapshots', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained('shared_tenants')->cascadeOnDelete();
            $t->string('measure_id', 30);
            $t->integer('numerator');
            $t->integer('denominator');
            $t->decimal('rate_pct', 5, 2)->nullable();
            $t->timestamp('computed_at');
            $t->timestamps();

            $t->index(['tenant_id', 'measure_id', 'computed_at'], 'quality_trend_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('emr_quality_measure_snapshots');
        Schema::dropIfExists('emr_quality_measures');
    }
};
