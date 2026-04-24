<?php

namespace App\Services\Ocr;

/**
 * Google Document AI OCR gateway stub — Phase M4.
 * Activated when GCP SDK + service-account key land. Throws until configured.
 */
class GoogleDocumentAiOcrGateway implements OcrGateway
{
    public function name(): string { return 'google_documentai'; }

    public function extractText(string $filePath): string
    {
        throw new \RuntimeException('GoogleDocumentAiOcrGateway: awaiting GCP SDK + service-account key.');
    }
}
