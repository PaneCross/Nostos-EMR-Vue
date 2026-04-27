<?php

// ─── RemittanceController ──────────────────────────────────────────────────────
//
// Manages 835 Electronic Remittance Advice (ERA) batch uploads and the
// associated claim-level data. Finance staff upload raw X12 835 EDI files;
// this controller validates the file, creates a RemittanceBatch record,
// and dispatches Process835RemittanceJob to parse it asynchronously.
//
// Route list:
//   POST /finance/remittance/upload       → upload()  : store file + dispatch job
//   GET  /finance/remittance              → index()   : Inertia batch list page
//   GET  /finance/remittance/{batch}      → show()    : Inertia batch detail page
//   GET  /finance/remittance/{batch}/claims → claims() : JSON claim list for a batch
//
// Authorization:
//   Write (upload):           finance, it_admin, super_admin
//   Read (index/show/claims): finance, it_admin, super_admin
//
// File upload validation:
//   - Max size: 5 MB (5120 KB)
//   - Accepted MIME types: text/plain, application/octet-stream
//   - Accepted extensions: .835, .txt, .edi, .x12
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Http\Controllers;

use App\Jobs\Process835RemittanceJob;
use App\Models\AuditLog;
use App\Models\RemittanceBatch;
use App\Models\RemittanceClaim;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class RemittanceController extends Controller
{
    // ── Department guard ──────────────────────────────────────────────────────

    /**
     * Abort 403 for users outside finance + it_admin (write access requires finance).
     * Super admins bypass all department checks.
     */
    private function authorizeRead(Request $request): void
    {
        $user = $request->user();
        abort_if(
            !$user->isSuperAdmin()
            && !in_array($user->department, ['finance', 'it_admin']),
            403
        );
    }

    /**
     * Abort 403 for users not in finance or it_admin.
     * Finance is the only department that should upload 835 ERA files.
     */
    private function authorizeWrite(Request $request): void
    {
        $user = $request->user();
        abort_if(
            !$user->isSuperAdmin()
            && !in_array($user->department, ['finance', 'it_admin']),
            403
        );
    }

    // ── Upload ────────────────────────────────────────────────────────────────

    /**
     * Validate and store an uploaded X12 835 ERA file.
     *
     * Creates a RemittanceBatch record with status='received', stores the
     * raw EDI content, and dispatches Process835RemittanceJob to parse it
     * asynchronously on the 'remittance' Horizon queue.
     *
     * Returns HTTP 201 with the new batch's ID and status so the frontend
     * can poll for processing completion.
     *
     * POST /finance/remittance/upload
     */
    public function upload(Request $request): JsonResponse
    {
        $this->authorizeWrite($request);

        // Validate the uploaded file
        $request->validate([
            'file' => [
                'required',
                'file',
                'max:5120', // 5 MB
                // Accept 835, txt, EDI, and X12 extensions
                function (string $attribute, mixed $value, callable $fail) {
                    $ext = strtolower($value->getClientOriginalExtension());
                    if (!in_array($ext, ['835', 'txt', 'edi', 'x12', 'dat'])) {
                        $fail('The file must be an X12 835 EDI file (.835, .txt, .edi, .x12, .dat).');
                    }
                },
            ],
        ]);

        $file     = $request->file('file');
        $fileName = $file->getClientOriginalName();
        $ediContent = file_get_contents($file->getRealPath());

        // Basic sanity check: the content should contain an ISA segment
        if (!str_contains($ediContent, 'ISA') && !str_contains($ediContent, 'isa')) {
            throw ValidationException::withMessages([
                'file' => ['The uploaded file does not appear to be a valid X12 EDI file (no ISA segment found).'],
            ]);
        }

        // Create the batch record in 'received' status
        $batch = RemittanceBatch::create([
            'tenant_id'           => $request->user()->tenant_id,
            'file_name'           => $fileName,
            'edi_835_content'     => $ediContent,
            'status'              => 'received',
            'source'              => 'manual_upload',
            'claim_count'         => 0,
            'paid_count'          => 0,
            'denied_count'        => 0,
            'adjustment_count'    => 0,
            'created_by_user_id'  => $request->user()->id,
        ]);

        // Audit log the upload
        AuditLog::record(
            action:       'remittance_batch.uploaded',
            resourceType: 'RemittanceBatch',
            resourceId:   $batch->id,
            tenantId:     $batch->tenant_id,
            userId:       $request->user()->id,
            newValues:    [
                'file_name' => $fileName,
                'status'    => 'received',
            ],
        );

        // Dispatch the async parser job : returns immediately (non-blocking)
        Process835RemittanceJob::dispatch($batch->id);

        return response()->json([
            'message'    => '835 file uploaded. Processing has started.',
            'batch_id'   => $batch->id,
            'status'     => $batch->status,
            'file_name'  => $batch->file_name,
        ], 201);
    }

    // ── Index ─────────────────────────────────────────────────────────────────

    /**
     * Render the Inertia remittance batch list page.
     *
     * Returns paginated batches (25 per page) with aggregate counts.
     * Excludes the raw edi_835_content column : never expose bulk EDI in list responses.
     *
     * GET /finance/remittance
     */
    public function index(Request $request): InertiaResponse
    {
        $this->authorizeRead($request);
        $tenantId = $request->user()->tenant_id;

        $batches = RemittanceBatch::forTenant($tenantId)
            ->orderBy('created_at', 'desc')
            ->paginate(25);

        // Map to API shape (excludes raw EDI content)
        $batchData = $batches->through(fn (RemittanceBatch $b) => $b->toApiArray());

        return Inertia::render('Finance/Remittance', [
            'batches'   => $batchData,
            'summary'   => $this->buildSummary($tenantId),
        ]);
    }

    /**
     * Build the aggregate summary shown at the top of the remittance index.
     * Returns totals for the most recent 30 days.
     */
    private function buildSummary(int $tenantId): array
    {
        $cutoff = now()->subDays(30);

        $recent = RemittanceBatch::forTenant($tenantId)
            ->where('created_at', '>=', $cutoff)
            ->get(['payment_amount', 'claim_count', 'paid_count', 'denied_count', 'status']);

        $processed = $recent->where('status', 'processed');

        return [
            'recent_batches'        => $recent->count(),
            'recent_payment_total'  => (float) $processed->sum('payment_amount'),
            'recent_claims'         => (int) $processed->sum('claim_count'),
            'recent_paid'           => (int) $processed->sum('paid_count'),
            'recent_denied'         => (int) $processed->sum('denied_count'),
            'processing_in_progress'=> $recent->whereIn('status', ['received', 'processing'])->count(),
        ];
    }

    // ── Show ──────────────────────────────────────────────────────────────────

    /**
     * Render the Inertia batch detail page.
     *
     * Includes batch metadata and a summary of its claims.
     * Full claim list is loaded separately via claims() JSON endpoint.
     *
     * GET /finance/remittance/{batch}
     */
    public function show(Request $request, RemittanceBatch $remittanceBatch): InertiaResponse
    {
        $this->authorizeRead($request);
        abort_if($remittanceBatch->tenant_id !== $request->user()->tenant_id, 403);

        // Aggregate claim stats for the batch detail header
        $claimStats = $remittanceBatch->claims()
            ->selectRaw("
                COUNT(*) as total,
                SUM(CASE WHEN claim_status = 'paid_full' THEN 1 ELSE 0 END) as paid_full,
                SUM(CASE WHEN claim_status = 'paid_partial' THEN 1 ELSE 0 END) as paid_partial,
                SUM(CASE WHEN claim_status = 'denied' THEN 1 ELSE 0 END) as denied,
                SUM(CASE WHEN claim_status = 'pending' THEN 1 ELSE 0 END) as pending,
                SUM(submitted_amount) as total_submitted,
                SUM(paid_amount) as total_paid
            ")
            ->first();

        return Inertia::render('Finance/Remittance', [
            'batch'      => $remittanceBatch->toApiArray(),
            'claimStats' => [
                'total'          => (int) ($claimStats->total ?? 0),
                'paid_full'      => (int) ($claimStats->paid_full ?? 0),
                'paid_partial'   => (int) ($claimStats->paid_partial ?? 0),
                'denied'         => (int) ($claimStats->denied ?? 0),
                'pending'        => (int) ($claimStats->pending ?? 0),
                'total_submitted'=> (float) ($claimStats->total_submitted ?? 0),
                'total_paid'     => (float) ($claimStats->total_paid ?? 0),
            ],
        ]);
    }

    // ── Claims ────────────────────────────────────────────────────────────────

    /**
     * Return a paginated JSON list of claims for a remittance batch.
     *
     * Used by the Finance/Remittance.tsx claim detail table.
     * Supports ?status= filter and ?page= pagination (20 per page).
     *
     * GET /finance/remittance/{batch}/claims
     */
    public function claims(Request $request, RemittanceBatch $remittanceBatch): JsonResponse
    {
        $this->authorizeRead($request);
        abort_if($remittanceBatch->tenant_id !== $request->user()->tenant_id, 403);

        $query = RemittanceClaim::where('remittance_batch_id', $remittanceBatch->id)
            ->with('adjustments')
            ->orderBy('claim_status')
            ->orderBy('id');

        // Optional status filter
        if ($request->filled('status')) {
            $query->where('claim_status', $request->query('status'));
        }

        $claims = $query->paginate(20);

        return response()->json([
            'data' => $claims->items() ? collect($claims->items())->map(fn (RemittanceClaim $c) => [
                'id'                     => $c->id,
                'patient_control_number' => $c->patient_control_number,
                'claim_status'           => $c->claim_status,
                'submitted_amount'       => (float) $c->submitted_amount,
                'allowed_amount'         => (float) $c->allowed_amount,
                'paid_amount'            => (float) $c->paid_amount,
                'patient_responsibility' => (float) $c->patient_responsibility,
                'payer_claim_number'     => $c->payer_claim_number,
                'service_date_from'      => $c->service_date_from,
                'service_date_to'        => $c->service_date_to,
                'remittance_date'        => $c->remittance_date,
                'encounter_log_id'       => $c->encounter_log_id,
                'adjustment_count'       => $c->adjustments->count(),
                'adjustment_total'       => (float) $c->adjustmentTotal(),
                'is_denied'              => $c->isDenied(),
                'is_paid'                => $c->isPaid(),
            ])->toArray() : [],
            'meta' => [
                'current_page' => $claims->currentPage(),
                'last_page'    => $claims->lastPage(),
                'per_page'     => $claims->perPage(),
                'total'        => $claims->total(),
            ],
        ]);
    }
}
