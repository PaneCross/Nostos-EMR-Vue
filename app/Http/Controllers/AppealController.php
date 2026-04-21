<?php

// ─── AppealController ─────────────────────────────────────────────────────────
// Index/show/file/decide/withdraw/close endpoints for §460.122 appeals.
// Also exposes the denial-notice PDF download endpoint.
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Http\Controllers;

use App\Http\Requests\DecideAppealRequest;
use App\Http\Requests\FileAppealRequest;
use App\Models\Appeal;
use App\Models\Participant;
use App\Models\Sdr;
use App\Models\ServiceDenialNotice;
use App\Services\AppealService;
use App\Services\ServiceDenialNoticeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class AppealController extends Controller
{
    public function __construct(
        private AppealService $appealService,
        private ServiceDenialNoticeService $denialService,
    ) {}

    /** GET /appeals — Inertia index page or JSON list. */
    public function index(Request $request): InertiaResponse|JsonResponse
    {
        $user = $request->user();

        $query = Appeal::forTenant($user->tenant_id)
            ->with([
                'participant:id,mrn,first_name,last_name',
                'denialNotice:id,sdr_id,reason_code,issued_at',
            ])
            ->orderByRaw("
                CASE status
                    WHEN 'received' THEN 0
                    WHEN 'acknowledged' THEN 1
                    WHEN 'under_review' THEN 2
                    WHEN 'external_review_requested' THEN 3
                    WHEN 'decided_upheld' THEN 4
                    WHEN 'decided_overturned' THEN 4
                    WHEN 'decided_partially_overturned' THEN 4
                    WHEN 'withdrawn' THEN 5
                    WHEN 'closed' THEN 6
                    ELSE 7
                END
            ")
            ->orderBy('internal_decision_due_at');

        if ($status = $request->input('status')) {
            if ($status === 'open') {
                $query->open();
            } elseif ($status === 'overdue') {
                $query->overdue();
            } else {
                $query->where('status', $status);
            }
        }

        $appeals = $query->paginate(50)->withQueryString();

        if ($request->wantsJson()) {
            return response()->json($appeals);
        }

        return Inertia::render('Appeals/Index', [
            'appeals' => $appeals,
            'filters' => $request->only(['status']),
        ]);
    }

    /** GET /appeals/{appeal} — detail page */
    public function show(Request $request, Appeal $appeal): InertiaResponse|JsonResponse
    {
        $user = $request->user();
        abort_if($appeal->tenant_id !== $user->tenant_id, 403);

        $appeal->load([
            'participant:id,mrn,first_name,last_name,dob',
            'denialNotice.sdr:id,request_type,description,status',
            'denialNotice.pdfDocument:id,file_name,file_path',
            'acknowledgmentPdf:id,file_name,file_path',
            'decisionPdf:id,file_name,file_path',
            'decidedBy:id,first_name,last_name',
            'events.actor:id,first_name,last_name',
        ]);

        if ($request->wantsJson()) {
            return response()->json($appeal);
        }

        return Inertia::render('Appeals/Show', [
            'appeal' => $appeal,
        ]);
    }

    /** POST /appeals — file a new appeal from a denial notice */
    public function store(FileAppealRequest $request): JsonResponse
    {
        $user = $request->user();
        $notice = ServiceDenialNotice::where('id', $request->validated('service_denial_notice_id'))
            ->where('tenant_id', $user->tenant_id)
            ->firstOrFail();

        if (! $notice->appealWindowOpen()) {
            abort(422, 'The appeal window for this denial notice has closed.');
        }

        $appeal = $this->appealService->file(
            notice:                 $notice,
            type:                   $request->validated('type'),
            filedBy:                $request->validated('filed_by'),
            filedByName:            $request->validated('filed_by_name'),
            filingReason:           $request->validated('filing_reason'),
            continuationOfBenefits: (bool) $request->validated('continuation_of_benefits'),
            actor:                  $user,
        );

        return response()->json($appeal, 201);
    }

    /** POST /appeals/{appeal}/acknowledge */
    public function acknowledge(Request $request, Appeal $appeal): JsonResponse
    {
        abort_if($appeal->tenant_id !== $request->user()->tenant_id, 403);
        $appeal = $this->appealService->acknowledge($appeal, $request->user());
        return response()->json($appeal);
    }

    /** POST /appeals/{appeal}/begin-review */
    public function beginReview(Request $request, Appeal $appeal): JsonResponse
    {
        abort_if($appeal->tenant_id !== $request->user()->tenant_id, 403);
        $appeal = $this->appealService->beginReview($appeal, $request->user());
        return response()->json($appeal);
    }

    /** POST /appeals/{appeal}/decide */
    public function decide(DecideAppealRequest $request, Appeal $appeal): JsonResponse
    {
        abort_if($appeal->tenant_id !== $request->user()->tenant_id, 403);
        $appeal = $this->appealService->decide(
            $appeal,
            $request->validated('outcome'),
            $request->validated('narrative'),
            $request->user(),
        );
        return response()->json($appeal);
    }

    /** POST /appeals/{appeal}/request-external */
    public function requestExternal(Request $request, Appeal $appeal): JsonResponse
    {
        abort_if($appeal->tenant_id !== $request->user()->tenant_id, 403);
        $validated = $request->validate(['narrative' => ['nullable', 'string', 'max:4000']]);
        $appeal = $this->appealService->requestExternalReview($appeal, $request->user(), $validated['narrative'] ?? null);
        return response()->json($appeal);
    }

    /** POST /appeals/{appeal}/withdraw */
    public function withdraw(Request $request, Appeal $appeal): JsonResponse
    {
        abort_if($appeal->tenant_id !== $request->user()->tenant_id, 403);
        $validated = $request->validate(['narrative' => ['nullable', 'string', 'max:4000']]);
        $appeal = $this->appealService->withdraw($appeal, $request->user(), $validated['narrative'] ?? null);
        return response()->json($appeal);
    }

    /** POST /appeals/{appeal}/close */
    public function close(Request $request, Appeal $appeal): JsonResponse
    {
        abort_if($appeal->tenant_id !== $request->user()->tenant_id, 403);
        $validated = $request->validate(['narrative' => ['nullable', 'string', 'max:4000']]);
        $appeal = $this->appealService->close($appeal, $request->user(), $validated['narrative'] ?? null);
        return response()->json($appeal);
    }

    // ── Denial notice endpoints ───────────────────────────────────────────────

    /** GET /denial-notices/{notice}/download — stream the PDF */
    public function downloadNoticePdf(Request $request, ServiceDenialNotice $notice)
    {
        abort_if($notice->tenant_id !== $request->user()->tenant_id, 403);
        abort_unless($notice->pdf_document_id, 404, 'Notice PDF has not been generated.');
        $doc = $notice->pdfDocument;
        abort_unless($doc && Storage::disk('local')->exists($doc->file_path), 404);
        return Response::download(Storage::disk('local')->path($doc->file_path), $doc->file_name);
    }

    /** GET /appeals/{appeal}/acknowledgment.pdf — stream ack letter */
    public function downloadAckPdf(Request $request, Appeal $appeal)
    {
        abort_if($appeal->tenant_id !== $request->user()->tenant_id, 403);
        abort_unless($appeal->acknowledgment_pdf_document_id, 404);
        $doc = $appeal->acknowledgmentPdf;
        abort_unless($doc && Storage::disk('local')->exists($doc->file_path), 404);
        return Response::download(Storage::disk('local')->path($doc->file_path), $doc->file_name);
    }

    /** GET /appeals/{appeal}/decision.pdf — stream decision letter */
    public function downloadDecisionPdf(Request $request, Appeal $appeal)
    {
        abort_if($appeal->tenant_id !== $request->user()->tenant_id, 403);
        abort_unless($appeal->decision_pdf_document_id, 404);
        $doc = $appeal->decisionPdf;
        abort_unless($doc && Storage::disk('local')->exists($doc->file_path), 404);
        return Response::download(Storage::disk('local')->path($doc->file_path), $doc->file_name);
    }
}
