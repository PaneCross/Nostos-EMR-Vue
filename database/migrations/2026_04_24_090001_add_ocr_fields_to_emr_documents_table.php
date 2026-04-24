<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('emr_documents', function (Blueprint $t) {
            $t->text('ocr_text')->nullable()->after('notes');
            $t->jsonb('ocr_extracted_fields')->nullable()->after('ocr_text');
            $t->timestamp('ocr_processed_at')->nullable()->after('ocr_extracted_fields');
            $t->string('ocr_engine', 30)->nullable()->after('ocr_processed_at');
        });
    }

    public function down(): void
    {
        Schema::table('emr_documents', function (Blueprint $t) {
            $t->dropColumn(['ocr_text', 'ocr_extracted_fields', 'ocr_processed_at', 'ocr_engine']);
        });
    }
};
