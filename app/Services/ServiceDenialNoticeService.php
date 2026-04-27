<?php

// ─── ServiceDenialNoticeService ───────────────────────────────────────────────
// Creates a ServiceDenialNotice for a denied SDR (or denied claim), renders
// the CMS-style denial letter PDF, stores it as a Document, and logs an audit
// event. Idempotent per (sdr_id, issued_at) : resending reprints the same PDF.
//
// Per 42 CFR §460.122 the notice must include:
//   - reason for denial (reason_code + narrative)
//   - right to appeal
//   - 30-day deadline to file appeal (standard)
//   - option for expedited appeal + external review
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Services;

use App\Models\AuditLog;
use App\Models\DenialRecord;
use App\Models\Document;
use App\Models\Participant;
use App\Models\Sdr;
use App\Models\ServiceDenialNotice;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class ServiceDenialNoticeService
{
    public const DEFAULT_DELIVERY_METHOD = 'mail';

    /**
     * Issue a denial notice for an SDR. Transitions the SDR to status=denied
     * if not already, then renders the PDF and returns the persisted notice.
     */
    public function issueForSdr(
        Sdr $sdr,
        string $reasonCode,
        string $reasonNarrative,
        User $issuedBy,
        string $deliveryMethod = self::DEFAULT_DELIVERY_METHOD,
    ): ServiceDenialNotice {
        return DB::transaction(function () use ($sdr, $reasonCode, $reasonNarrative, $issuedBy, $deliveryMethod) {
            if ($sdr->status !== 'denied') {
                $sdr->update(['status' => 'denied']);
            }

            $issuedAt = now();
            $notice = ServiceDenialNotice::create([
                'tenant_id'          => $sdr->tenant_id,
                'participant_id'     => $sdr->participant_id,
                'sdr_id'             => $sdr->id,
                'reason_code'        => $reasonCode,
                'reason_narrative'   => $reasonNarrative,
                'issued_by_user_id'  => $issuedBy->id,
                'issued_at'          => $issuedAt,
                'delivery_method'    => $deliveryMethod,
                'appeal_deadline_at' => $issuedAt->copy()->addDays(ServiceDenialNotice::APPEAL_DEADLINE_DAYS),
            ]);

            $doc = $this->generatePdf($notice);
            $notice->update(['pdf_document_id' => $doc->id]);

            AuditLog::record(
                action:       'service_denial_notice.issued',
                tenantId:     $sdr->tenant_id,
                userId:       $issuedBy->id,
                resourceType: 'service_denial_notice',
                resourceId:   $notice->id,
                description:  "Denial notice issued for SDR-{$sdr->id}: {$reasonCode}",
            );

            return $notice->fresh(['pdfDocument', 'sdr', 'participant']);
        });
    }

    /** Render the denial notice as a PDF, persist to storage, return the Document row. */
    public function generatePdf(ServiceDenialNotice $notice): Document
    {
        $participant = Participant::findOrFail($notice->participant_id);
        $issuedBy    = User::find($notice->issued_by_user_id);
        $notice->loadMissing('sdr');

        $html = view('pdf.service_denial_notice', [
            'notice'      => $notice,
            'participant' => $participant,
            'issuedBy'    => $issuedBy,
        ])->render();

        $pdfBinary = Pdf::loadHTML($html)->output();

        $filename = sprintf(
            'denial-notices/%d/SDR-%d-DENIAL-%s.pdf',
            $participant->id,
            $notice->sdr_id ?? 0,
            $notice->issued_at->format('Ymd-His'),
        );

        Storage::disk('local')->put($filename, $pdfBinary);

        return Document::create([
            'tenant_id'           => $notice->tenant_id,
            'participant_id'      => $participant->id,
            'uploaded_by_user_id' => $notice->issued_by_user_id,
            'file_path'           => $filename,
            'file_name'           => basename($filename),
            'file_type'           => 'pdf',
            'file_size_bytes'     => strlen($pdfBinary),
            'document_category'   => 'legal',
            'description'         => "Service Denial Notice : SDR-{$notice->sdr_id}",
        ]);
    }
}
