<?php

// ─── TesseractOcrGateway ─────────────────────────────────────────────────────
// Phase G6. Shell-exec Tesseract CLI (free, Apache-2.0). Falls back to empty
// string if the binary isn't installed. Production should consider AWS
// Textract / Google DocumentAI (paywall) for higher accuracy on handwriting.
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Services\Ocr;

class TesseractOcrGateway implements OcrGateway
{
    public function name(): string { return 'tesseract'; }

    public function extractText(string $filePath): string
    {
        if (! file_exists($filePath)) return '';
        if (! $this->tesseractAvailable()) return '';

        $outBase = sys_get_temp_dir() . '/ocr_' . uniqid();
        $cmd = sprintf('tesseract %s %s 2>/dev/null', escapeshellarg($filePath), escapeshellarg($outBase));
        @exec($cmd, $_, $rc);
        if ($rc !== 0) return '';
        $out = @file_get_contents($outBase . '.txt') ?: '';
        @unlink($outBase . '.txt');
        return $out;
    }

    private function tesseractAvailable(): bool
    {
        @exec('which tesseract 2>/dev/null', $out, $rc);
        return $rc === 0;
    }
}
