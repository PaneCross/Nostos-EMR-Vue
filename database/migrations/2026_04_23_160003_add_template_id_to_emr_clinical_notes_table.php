<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ─── Phase B7 — Track which template a note was created from ────────────────
return new class extends Migration {
    public function up(): void
    {
        Schema::table('emr_clinical_notes', function (Blueprint $t) {
            $t->foreignId('note_template_id')->nullable()
                ->after('note_type')
                ->constrained('emr_note_templates')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('emr_clinical_notes', function (Blueprint $t) {
            $t->dropConstrainedForeignId('note_template_id');
        });
    }
};
