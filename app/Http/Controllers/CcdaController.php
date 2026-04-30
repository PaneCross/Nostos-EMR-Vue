<?php

// ─── CcdaController ──────────────────────────────────────────────────────────
// Phase 8 (MVP roadmap). Export a participant's C-CDA (R2.1 CCD) and import a
// received C-CDA for review. Import returns parsed summary only : staff then
// drives reconciliation through existing MedReconciliation workflow.
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Participant;
use App\Services\CcdaExportService;
use App\Services\CcdaImportService;
use App\Services\PhiDisclosureService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class CcdaController extends Controller
{
    public function __construct(
        private CcdaExportService $exporter,
        private CcdaImportService $importer,
        private PhiDisclosureService $disclosures,
    ) {}

    private function gate(): void
    {
        $u = Auth::user();
        abort_if(!$u, 401);
        $ok = $u->isSuperAdmin()
            || in_array($u->department, ['primary_care', 'social_work', 'qa_compliance', 'it_admin', 'pharmacy']);
        abort_unless($ok, 403);
    }

    public function export(Participant $participant)
    {
        $this->gate();
        $u = Auth::user();
        abort_unless($participant->tenant_id === $u->effectiveTenantId(), 404);

        $xml = $this->exporter->build($participant);

        AuditLog::record(
            action: 'ccda.exported',
            tenantId: $u->tenant_id,
            userId: $u->id,
            resourceType: 'participant',
            resourceId: $participant->id,
            description: 'C-CDA export',
        );

        // Phase Q2 : HIPAA §164.528 Accounting of Disclosures
        $this->disclosures->record(
            tenantId: $u->tenant_id,
            participantId: $participant->id,
            recipientType: 'provider',
            recipientName: trim(($u->first_name ?? '') . ' ' . ($u->last_name ?? '')) ?: ('User #' . $u->id),
            purpose: 'treatment',
            method: 'portal',
            recordsDescribed: 'C-CDA R2.1 Continuity of Care Document (XML).',
            disclosedByUserId: $u->id,
            related: $participant,
        );

        $filename = sprintf('ccda-%s-%s.xml',
            $participant->mrn ?: $participant->id,
            now()->format('Ymd-His')
        );

        return response($xml, 200, [
            'Content-Type'        => 'application/xml',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
    }

    public function import(Request $request, Participant $participant)
    {
        $this->gate();
        $u = Auth::user();
        abort_unless($participant->tenant_id === $u->effectiveTenantId(), 404);

        $request->validate([
            'ccda_file' => 'required|file|mimetypes:application/xml,text/xml,text/plain|max:10240',
        ]);

        $content = (string) file_get_contents($request->file('ccda_file')->getRealPath());
        $summary = $this->importer->parse($content);

        AuditLog::record(
            action: 'ccda.imported_preview',
            tenantId: $u->tenant_id,
            userId: $u->id,
            resourceType: 'participant',
            resourceId: $participant->id,
            description: 'C-CDA import preview: '
                . count($summary['allergies']) . ' allergies, '
                . count($summary['medications']) . ' medications, '
                . count($summary['problems']) . ' problems',
        );

        return response()->json([
            'summary'     => $summary,
            'honest_label' => 'Parsed preview only : nothing was written to the chart. '
                            . 'Use the MedReconciliation workflow to accept entries.',
        ]);
    }
}
