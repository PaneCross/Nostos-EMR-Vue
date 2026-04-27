<?php

// ─── PhiDisclosureController : Phase P2 ─────────────────────────────────────
// Read endpoints for the HIPAA §164.528 Accounting of Disclosures.
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Participant;
use App\Models\PhiDisclosure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class PhiDisclosureController extends Controller
{
    private function gateStaff(): void
    {
        $u = Auth::user();
        abort_if(! $u, 401);
        $allow = ['qa_compliance', 'it_admin', 'social_work', 'primary_care', 'executive'];
        abort_unless($u->isSuperAdmin() || in_array($u->department, $allow, true), 403);
    }

    /**
     * GET /participants/{participant}/phi-disclosures
     * Per-participant disclosure log; honored for ROI fulfillment + patient self-request.
     */
    public function forParticipant(Request $request, Participant $participant): JsonResponse
    {
        $this->gateStaff();
        $u = Auth::user();
        abort_if($participant->tenant_id !== $u->tenant_id, 403);

        $rows = PhiDisclosure::forTenant($u->tenant_id)
            ->forParticipant($participant->id)
            ->accountingPeriod()
            ->orderByDesc('disclosed_at')
            ->paginate(50);

        AuditLog::record(
            action: 'phi_disclosure.log_viewed',
            tenantId: $u->tenant_id,
            userId: $u->id,
            resourceType: 'participant',
            resourceId: $participant->id,
            description: 'PHI disclosure log viewed for participant.',
        );

        return response()->json($rows);
    }

    /**
     * GET /it-admin/phi-disclosures
     * Tenant-wide disclosure log for QA / compliance review.
     */
    public function index(Request $request): JsonResponse|\Inertia\Response
    {
        $this->gateStaff();
        $u = Auth::user();

        $rows = PhiDisclosure::forTenant($u->tenant_id)
            ->accountingPeriod()
            ->with('participant:id,mrn,first_name,last_name', 'disclosedBy:id,first_name,last_name')
            ->orderByDesc('disclosed_at')
            ->paginate(50);

        if (! $request->wantsJson()) {
            return \Inertia\Inertia::render('ItAdmin/PhiDisclosures', [
                'disclosures' => $rows,
            ]);
        }
        return response()->json($rows);
    }
}
