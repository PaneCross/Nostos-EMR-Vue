<?php

namespace App\Http\Controllers;

use App\Models\Participant;
use App\Models\ProResponse;
use App\Models\ProSurvey;
use App\Services\ProService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ProController extends Controller
{
    public function __construct(private ProService $svc) {}

    private function gate(): void
    {
        $u = Auth::user();
        abort_if(!$u, 401);
    }

    public function surveys(Request $request): JsonResponse
    {
        $this->gate();
        return response()->json(['surveys' => ProSurvey::whereNull('tenant_id')->get()]);
    }

    /** POST /pro/responses : staff logs participant response (or portal calls same). */
    public function storeResponse(Request $request): JsonResponse
    {
        $this->gate();
        $u = Auth::user();
        $validated = $request->validate([
            'participant_id' => 'required|integer|exists:emr_participants,id',
            'survey_id'      => 'required|integer|exists:emr_pro_surveys,id',
            'answers'        => 'required|array',
            'delivery_channel' => 'nullable|in:sms,portal,phone',
        ]);
        $p = Participant::findOrFail($validated['participant_id']);
        abort_if($p->tenant_id !== $u->tenant_id, 403);
        $survey = ProSurvey::findOrFail($validated['survey_id']);

        $r = $this->svc->recordResponse($p, $survey, $validated['answers'], $validated['delivery_channel'] ?? 'portal');
        return response()->json(['response' => $r], 201);
    }

    /** GET /participants/{p}/pro-trend */
    public function trend(Request $request, Participant $participant): JsonResponse
    {
        $this->gate();
        $u = Auth::user();
        abort_if($participant->tenant_id !== $u->tenant_id, 403);
        $rows = ProResponse::forTenant($u->tenant_id)
            ->where('participant_id', $participant->id)
            ->orderByDesc('received_at')->limit(52)->get();
        return response()->json(['rows' => $rows->groupBy('survey_id')]);
    }
}
