<?php

// ─── OcrService ──────────────────────────────────────────────────────────────
// Phase G6. Runs OCR on a Document's file + runs simple regex extraction on
// common fields (hospital discharge summaries). Writes results to the
// document row.
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Services\Ocr;

use App\Models\AuditLog;
use App\Models\Document;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

class OcrService
{
    public function __construct(private OcrGateway $gateway) {}

    public function process(Document $doc, ?User $user = null): Document
    {
        $disk = Storage::disk('local');
        $path = $doc->file_path;
        $abs = $path && $disk->exists($path) ? $disk->path($path) : null;

        $text = $abs ? $this->gateway->extractText($abs) : '';
        $extracted = $text !== '' ? $this->extractFields($text) : [];

        $doc->update([
            'ocr_text'             => $text ?: null,
            'ocr_extracted_fields' => $extracted ?: null,
            'ocr_processed_at'     => now(),
            'ocr_engine'           => $this->gateway->name(),
        ]);

        AuditLog::record(
            action: 'document.ocr_processed',
            tenantId: $doc->tenant_id,
            userId: $user?->id,
            resourceType: 'document',
            resourceId: $doc->id,
            description: "OCR processed using {$this->gateway->name()}; " . strlen($text) . ' chars extracted.',
        );

        return $doc->fresh();
    }

    /** Naive regex extraction of common discharge-summary fields. */
    public function extractFields(string $text): array
    {
        $out = [];
        if (preg_match('/Admit(?:ted|ted on|sion)[\s:]+([A-Za-z0-9\/\-, ]{5,30})/i', $text, $m))
            $out['admit_date'] = trim($m[1]);
        if (preg_match('/Discharge(?:d|d on|d date|d\:)[\s:]+([A-Za-z0-9\/\-, ]{5,30})/i', $text, $m))
            $out['discharge_date'] = trim($m[1]);
        if (preg_match('/(?:Primary )?Diagnos[ei]s[\s:]+([^\r\n]{5,200})/i', $text, $m))
            $out['primary_diagnosis'] = trim($m[1]);
        if (preg_match('/Discharge Medication[s]?[\s:]+([^\r\n]{5,500})/i', $text, $m))
            $out['discharge_medications'] = trim($m[1]);
        if (preg_match('/Follow[- ]?up[\s:]+([^\r\n]{5,300})/i', $text, $m))
            $out['followup'] = trim($m[1]);
        return $out;
    }
}
