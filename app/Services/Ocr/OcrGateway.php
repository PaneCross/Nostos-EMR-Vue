<?php

namespace App\Services\Ocr;

interface OcrGateway
{
    /** Engine name (for emr_documents.ocr_engine). */
    public function name(): string;

    /**
     * Extract plain text from the given file path. Return empty string
     * when OCR is unavailable.
     */
    public function extractText(string $filePath): string;
}
