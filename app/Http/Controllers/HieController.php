<?php

// ─── HieController — Phase M3 ────────────────────────────────────────────────
// Publish CCD + document-query endpoint. Delegates to the configured HIE
// gateway (default null). OAuth-protected reuse of existing FHIR token guard.
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Participant;
use App\Services\CcdaExportService;
use App\Services\Hie\HieGateway;
use App\Services\Hie\NullHieGateway;
use App\Services\Hie\SequoiaHieGateway;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;

class HieController extends Controller
{
    private function gateway(): HieGateway
    {
        $driver = config('services.hie.driver', 'null');
        return match ($driver) {
            'sequoia' => new SequoiaHieGateway(),
            default   => new NullHieGateway(),
        };
    }

    private function gate(Request $r): void
    {
        $u = Auth::user();
        abort_if(! $u, 401);
        $allow = ['primary_care', 'home_care', 'qa_compliance', 'it_admin'];
        abort_unless($u->isSuperAdmin() || in_array($u->department, $allow, true), 403);
    }

    /** POST /participants/{p}/hie/publish — ship CCD to the HIE. */
    public function publish(Request $r, Participant $participant, CcdaExportService $ccd): JsonResponse
    {
        $this->gate($r);
        $u = Auth::user();
        abort_if($participant->tenant_id !== $u->tenant_id, 403);

        $xml = $ccd->build($participant);
        $result = $this->gateway()->publishCcd($participant, $xml);

        AuditLog::record(
            action: 'hie.ccd_published',
            tenantId: $participant->tenant_id,
            userId: $u->id,
            resourceType: 'participant',
            resourceId: $participant->id,
            description: "CCD published via {$this->gateway()->name()} ({$result['transmission_id']}).",
        );

        return response()->json(['gateway' => $this->gateway()->name(), 'result' => $result]);
    }

    /** GET /participants/{p}/hie/documents — query HIE for prior documents. */
    public function documents(Request $r, Participant $participant): JsonResponse
    {
        $this->gate($r);
        $u = Auth::user();
        abort_if($participant->tenant_id !== $u->tenant_id, 403);

        return response()->json([
            'gateway'   => $this->gateway()->name(),
            'documents' => $this->gateway()->queryDocuments($participant),
        ]);
    }

    /** GET /hie/ccd/{participant} — returns a fresh CCD XML (OAuth-protected). */
    public function ccd(Request $r, Participant $participant, CcdaExportService $ccd): Response
    {
        // Reuse existing FHIR API token guard via middleware (registered in routes).
        $u = Auth::user();
        if (! $u) abort(401);
        abort_if($participant->tenant_id !== $u->tenant_id, 403);
        $xml = $ccd->build($participant);

        AuditLog::record(
            action: 'hie.ccd_served',
            tenantId: $participant->tenant_id,
            userId: $u->id,
            resourceType: 'participant',
            resourceId: $participant->id,
            description: 'CCD document served via HIE endpoint.',
        );

        return response($xml, 200, ['Content-Type' => 'application/xml']);
    }
}
