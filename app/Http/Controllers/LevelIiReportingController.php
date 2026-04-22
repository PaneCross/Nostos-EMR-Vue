<?php

// ─── LevelIiReportingController ───────────────────────────────────────────────
// CMS Level I / Level II quarterly reporting UI + actions.
//   GET  /compliance/level-ii-reporting             — Inertia index
//   POST /compliance/level-ii-reporting             — generate/regenerate (year, quarter)
//   POST /compliance/level-ii-reporting/{sub}/mark-submitted — honest flag only
//   GET  /compliance/level-ii-reporting/{sub}/download       — stream CSV
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Http\Controllers;

use App\Models\LevelIiSubmission;
use App\Models\Tenant;
use App\Services\LevelIiReportingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class LevelIiReportingController extends Controller
{
    public function __construct(private LevelIiReportingService $service) {}

    private function gate(Request $request): void
    {
        $u = $request->user();
        abort_unless($u, 401);
        abort_unless(
            $u->isSuperAdmin()
                || in_array($u->department, ['qa_compliance', 'finance', 'it_admin'], true),
            403,
            'Only QA / Compliance, Finance, and IT Admin may manage Level I/II reporting.'
        );
    }

    public function index(Request $request): InertiaResponse
    {
        $this->gate($request);
        $tenantId = $request->user()->tenant_id;

        $submissions = LevelIiSubmission::where('tenant_id', $tenantId)
            ->with(['generatedBy:id,first_name,last_name', 'markedSubmittedBy:id,first_name,last_name'])
            ->orderByDesc('year')
            ->orderByDesc('quarter')
            ->get()
            ->map(fn (LevelIiSubmission $s) => [
                'id'                                => $s->id,
                'year'                              => $s->year,
                'quarter'                           => $s->quarter,
                'label'                             => $s->label(),
                'generated_at'                      => $s->generated_at?->toIso8601String(),
                'generated_by'                      => $s->generatedBy
                    ? $s->generatedBy->first_name . ' ' . $s->generatedBy->last_name
                    : null,
                'indicators_snapshot'               => $s->indicators_snapshot,
                'marked_cms_submitted_at'           => $s->marked_cms_submitted_at?->toIso8601String(),
                'marked_cms_submitted_by'           => $s->markedSubmittedBy
                    ? $s->markedSubmittedBy->first_name . ' ' . $s->markedSubmittedBy->last_name
                    : null,
                'marked_cms_submitted_notes'        => $s->marked_cms_submitted_notes,
                'csv_available'                     => (bool) $s->csv_path,
            ]);

        return Inertia::render('Compliance/LevelIiReporting', [
            'submissions'   => $submissions,
            'current_year'  => (int) now()->format('Y'),
            'current_quarter' => (int) ceil((int) now()->format('n') / 3),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->gate($request);

        $validated = $request->validate([
            'year'    => ['required', 'integer', 'min:2000', 'max:2100'],
            'quarter' => ['required', 'integer', 'in:1,2,3,4'],
        ]);

        $tenant = Tenant::findOrFail($request->user()->tenant_id);
        $submission = $this->service->generate(
            $tenant,
            (int) $validated['year'],
            (int) $validated['quarter'],
            $request->user(),
        );

        return response()->json($submission, 201);
    }

    public function markSubmitted(Request $request, LevelIiSubmission $submission): JsonResponse
    {
        $this->gate($request);
        abort_if($submission->tenant_id !== $request->user()->tenant_id, 403);

        $validated = $request->validate([
            'notes' => ['nullable', 'string', 'max:4000'],
        ]);

        $submission = $this->service->markCmsSubmitted(
            $submission,
            $request->user(),
            $validated['notes'] ?? null,
        );

        return response()->json($submission);
    }

    public function download(Request $request, LevelIiSubmission $submission)
    {
        $this->gate($request);
        abort_if($submission->tenant_id !== $request->user()->tenant_id, 403);
        abort_unless(
            $submission->csv_path && Storage::disk('local')->exists($submission->csv_path),
            404,
            'CSV has not been generated.'
        );

        return Response::download(
            Storage::disk('local')->path($submission->csv_path),
            "Level-II-{$submission->year}-Q{$submission->quarter}.csv",
        );
    }
}
