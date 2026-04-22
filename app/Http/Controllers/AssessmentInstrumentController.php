<?php

// ─── AssessmentInstrumentController ──────────────────────────────────────────
// Phase 13.2. Exposes the scored-instrument definitions + a score endpoint
// the UI can call to compute the total before persisting the Assessment.
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Http\Controllers;

use App\Services\AssessmentScoringService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AssessmentInstrumentController extends Controller
{
    public function __construct(private AssessmentScoringService $svc) {}

    private function gate(): void
    {
        $u = Auth::user();
        abort_if(!$u, 401);
    }

    public function index(): JsonResponse
    {
        $this->gate();
        $instruments = [];
        foreach (AssessmentScoringService::INSTRUMENTS as $code) {
            $def = $this->svc->definition($code);
            if ($def) $instruments[] = ['code' => $code, 'title' => $def['title'], 'max_score' => $def['max_score']];
        }
        return response()->json(['instruments' => $instruments]);
    }

    public function show(string $instrument): JsonResponse
    {
        $this->gate();
        $def = $this->svc->definition($instrument);
        abort_unless($def, 404, 'Unknown instrument.');
        return response()->json(['definition' => $def]);
    }

    public function score(Request $request, string $instrument): JsonResponse
    {
        $this->gate();
        $data = $request->validate([
            'responses' => 'required|array',
        ]);
        $result = $this->svc->score($instrument, $data['responses']);
        abort_unless($result, 404, 'Unknown instrument.');
        return response()->json(['score' => $result]);
    }
}
