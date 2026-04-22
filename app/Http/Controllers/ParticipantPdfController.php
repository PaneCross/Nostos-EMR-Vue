<?php

// ─── ParticipantPdfController ────────────────────────────────────────────────
// Phase 14.1. Streams the four printable participant PDFs.
// GET /participants/{participant}/pdf/{kind}
//   kind ∈ {facesheet, care_plan, medication_list, allergy_list}
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Participant;
use App\Services\ParticipantPdfService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ParticipantPdfController extends Controller
{
    public function __construct(private ParticipantPdfService $svc) {}

    public function generate(Request $request, Participant $participant, string $kind)
    {
        $u = Auth::user();
        abort_if(!$u, 401);
        abort_unless($participant->tenant_id === $u->tenant_id, 404);
        abort_unless(
            $u->isSuperAdmin()
            || in_array($u->department, [
                'primary_care', 'therapies', 'social_work', 'behavioral_health',
                'dietary', 'pharmacy', 'home_care',
                'enrollment', 'qa_compliance', 'finance', 'it_admin', 'idt',
            ]),
            403
        );
        abort_unless(in_array($kind, ParticipantPdfService::KINDS, true), 404);

        $pdf = $this->svc->render($participant, $kind);

        AuditLog::record(
            action: 'participant.pdf_generated',
            tenantId: $u->tenant_id,
            userId: $u->id,
            resourceType: 'participant',
            resourceId: $participant->id,
            description: "Participant PDF generated: {$kind}",
        );

        $filename = sprintf('%s-%s-%s.pdf',
            $kind,
            $participant->mrn ?: $participant->id,
            now()->format('Ymd-His')
        );

        return response($pdf, 200, [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
    }
}
