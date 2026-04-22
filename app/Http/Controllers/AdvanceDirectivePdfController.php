<?php

// ─── AdvanceDirectivePdfController ───────────────────────────────────────────
// Phase 8 (MVP roadmap). Streams a generated advance-directive PDF for a
// participant. Query `type` selects template (dnr|polst|healthcare_proxy|
// living_will|combined); defaults to participant's current
// advance_directive_type or 'dnr'.
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Participant;
use App\Services\AdvanceDirectivePdfService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AdvanceDirectivePdfController extends Controller
{
    public function __construct(private AdvanceDirectivePdfService $service) {}

    public function generate(Request $request, Participant $participant)
    {
        $u = Auth::user();
        abort_if(!$u, 401);
        abort_unless($participant->tenant_id === $u->tenant_id, 404);

        $ok = $u->isSuperAdmin()
            || in_array($u->department, [
                'primary_care', 'nursing', 'social_work',
                'qa_compliance', 'it_admin', 'enrollment',
            ]);
        abort_unless($ok, 403);

        $type = $request->query('type') ?: ($participant->advance_directive_type ?: 'dnr');
        $pdf = $this->service->render($participant, (string) $type);

        AuditLog::record(
            action: 'advance_directive.pdf_generated',
            tenantId: $u->tenant_id,
            userId: $u->id,
            resourceType: 'participant',
            resourceId: $participant->id,
            description: "Advance directive PDF generated: type={$type}",
        );

        $filename = sprintf(
            'advance-directive-%s-%s-%s.pdf',
            $participant->mrn ?: $participant->id,
            $type,
            now()->format('Ymd-His')
        );

        return response($pdf, 200, [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
    }
}
