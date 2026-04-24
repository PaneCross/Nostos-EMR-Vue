<?php

namespace App\Services\Ocr;

class NullOcrGateway implements OcrGateway
{
    public function name(): string { return 'null'; }

    public function extractText(string $filePath): string
    {
        // Default: no OCR. Safe to install without paying for Textract/DocumentAI.
        return '';
    }
}
