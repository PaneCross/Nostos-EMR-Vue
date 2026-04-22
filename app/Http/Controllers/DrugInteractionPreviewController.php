<?php

// ─── DrugInteractionPreviewController ────────────────────────────────────────
// Phase 13.3. Pre-save interaction preview. Returns any conflicts between a
// proposed drug and the participant's currently-active medications WITHOUT
// persisting anything. UI shows these as warnings before the prescriber hits
// Save. On actual save, MedicationController::store continues to call
// DrugInteractionService::checkInteractions to create persistent alerts.
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Http\Controllers;

use App\Models\Participant;
use App\Services\DrugInteractionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class DrugInteractionPreviewController extends Controller
{
    public function __construct(private DrugInteractionService $svc) {}

    public function preview(Request $request, Participant $participant): JsonResponse
    {
        $u = Auth::user();
        abort_if(!$u, 401);
        abort_unless($participant->tenant_id === $u->tenant_id, 404);
        abort_unless(
            $u->isSuperAdmin()
            || in_array($u->department, ['primary_care', 'pharmacy', 'it_admin']),
            403
        );

        $data = $request->validate([
            'drug_name'   => 'required|string|max:200',
            'rxnorm_code' => 'nullable|string|max:20',
        ]);

        $hits = $this->svc->previewInteractions(
            $participant,
            $data['drug_name'],
            $data['rxnorm_code'] ?? null,
        );

        return response()->json([
            'interactions' => $hits,
            'has_any'      => count($hits) > 0,
            'has_major'    => count(array_filter($hits, fn ($h) => in_array($h['severity'], ['major', 'contraindicated'], true))) > 0,
        ]);
    }
}
