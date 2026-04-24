<?php

namespace App\Http\Controllers;

use App\Models\AdverseDrugEvent;
use App\Models\Participant;
use App\Services\AdeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AdverseDrugEventController extends Controller
{
    public function __construct(private AdeService $svc) {}

    private function gate(): void
    {
        $u = Auth::user();
        abort_if(!$u, 401);
        $allow = ['primary_care', 'pharmacy', 'home_care', 'qa_compliance', 'it_admin'];
        abort_unless($u->isSuperAdmin() || in_array($u->department, $allow, true), 403);
    }

    private function requireSameTenant($r, $u): void { abort_if($r->tenant_id !== $u->tenant_id, 403); }

    public function index(Request $request, Participant $participant): JsonResponse
    {
        $this->gate();
        $u = Auth::user();
        $this->requireSameTenant($participant, $u);
        $events = AdverseDrugEvent::forTenant($u->tenant_id)
            ->where('participant_id', $participant->id)
            ->with('medication:id,drug_name')
            ->orderByDesc('onset_date')->get();
        return response()->json(['events' => $events]);
    }

    public function store(Request $request, Participant $participant): JsonResponse
    {
        $this->gate();
        $u = Auth::user();
        $this->requireSameTenant($participant, $u);

        $validated = $request->validate([
            'medication_id'        => 'nullable|integer|exists:emr_medications,id',
            'onset_date'           => 'required|date|before_or_equal:today',
            'severity'             => 'required|in:' . implode(',', AdverseDrugEvent::SEVERITIES),
            'causality'            => 'required|in:' . implode(',', AdverseDrugEvent::CAUSALITIES),
            'reaction_description' => 'required|string|min:5|max:4000',
            'outcome_text'         => 'nullable|string|max:4000',
        ]);

        $ade = $this->svc->record($participant, $u, $validated);
        return response()->json(['event' => $ade->fresh(['medication:id,drug_name'])], 201);
    }

    public function markReported(Request $request, AdverseDrugEvent $ade): JsonResponse
    {
        $this->gate();
        $u = Auth::user();
        $this->requireSameTenant($ade, $u);
        abort_unless($ade->requiresMedwatch(), 422, 'MedWatch reporting is only required for severe+ events.');

        $validated = $request->validate([
            'medwatch_tracking_number' => 'required|string|max:50',
        ]);
        return response()->json(['event' => $this->svc->markReported($ade, $u, $validated['medwatch_tracking_number'])]);
    }
}
