<?php

// ─── QapiAnnualEvaluationController ───────────────────────────────────────────
// UI + actions for the §460.200 annual QAPI evaluation artifact.
//   GET  /qapi/evaluations                — Inertia index
//   POST /qapi/evaluations                — generate (or regenerate) for a year
//   POST /qapi/evaluations/{eval}/review  — stamp governing body review
//   GET  /qapi/evaluations/{eval}/download — download PDF
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Http\Controllers;

use App\Models\QapiAnnualEvaluation;
use App\Models\Tenant;
use App\Services\QapiAnnualEvaluationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class QapiAnnualEvaluationController extends Controller
{
    public function __construct(private QapiAnnualEvaluationService $service) {}

    private function gate(Request $request): void
    {
        $u = $request->user();
        abort_unless($u, 401);
        abort_unless(
            $u->isSuperAdmin()
                || in_array($u->department, ['qa_compliance', 'it_admin'], true),
            403,
            'Only QA/Compliance and IT Admin may manage QAPI annual evaluations.'
        );
    }

    public function index(Request $request): InertiaResponse
    {
        $this->gate($request);
        $tenantId = $request->user()->tenant_id;

        $evaluations = QapiAnnualEvaluation::where('tenant_id', $tenantId)
            ->with(['generatedBy:id,first_name,last_name', 'governingBodyReviewer:id,first_name,last_name'])
            ->orderByDesc('year')
            ->get()
            ->map(fn ($e) => [
                'id'                                => $e->id,
                'year'                              => $e->year,
                'generated_at'                      => $e->generated_at?->toIso8601String(),
                'generated_by'                      => $e->generatedBy
                    ? $e->generatedBy->first_name . ' ' . $e->generatedBy->last_name
                    : null,
                'summary_snapshot'                  => $e->summary_snapshot,
                'governing_body_reviewed_at'        => $e->governing_body_reviewed_at?->toIso8601String(),
                'governing_body_reviewer'           => $e->governingBodyReviewer
                    ? $e->governingBodyReviewer->first_name . ' ' . $e->governingBodyReviewer->last_name
                    : null,
                'governing_body_notes'              => $e->governing_body_notes,
                'pdf_available'                     => (bool) $e->pdf_path,
            ]);

        return Inertia::render('Qapi/Evaluations', [
            'evaluations'  => $evaluations,
            'current_year' => (int) now()->format('Y'),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->gate($request);

        $validated = $request->validate([
            'year' => ['required', 'integer', 'min:2000', 'max:2100'],
        ]);

        $tenant = Tenant::findOrFail($request->user()->tenant_id);
        $evaluation = $this->service->generate($tenant, (int) $validated['year'], $request->user());

        return response()->json($evaluation, 201);
    }

    public function recordReview(Request $request, QapiAnnualEvaluation $evaluation): JsonResponse
    {
        $this->gate($request);
        abort_if($evaluation->tenant_id !== $request->user()->tenant_id, 403);

        $validated = $request->validate([
            'notes' => ['nullable', 'string', 'max:4000'],
        ]);

        $evaluation = $this->service->recordGoverningBodyReview(
            $evaluation,
            $request->user(),
            $validated['notes'] ?? null,
        );

        return response()->json($evaluation);
    }

    public function download(Request $request, QapiAnnualEvaluation $evaluation)
    {
        $this->gate($request);
        abort_if($evaluation->tenant_id !== $request->user()->tenant_id, 403);
        abort_unless($evaluation->pdf_path && Storage::disk('local')->exists($evaluation->pdf_path),
            404, 'PDF has not been generated.');

        return Response::download(
            Storage::disk('local')->path($evaluation->pdf_path),
            "QAPI-Annual-{$evaluation->year}.pdf",
        );
    }
}
