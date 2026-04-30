<?php

// ─── EnrollmentReconciliationController ───────────────────────────────────────
// Upload + view CMS MMR/TRR files and manage the discrepancy worklist.
//
// Access gate: finance, qa_compliance, enrollment admin, it_admin, super_admin.
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\MmrFile;
use App\Models\MmrRecord;
use App\Models\TrrFile;
use App\Services\EnrollmentReconciliationService;
use App\Services\MmrParserService;
use App\Services\TrrParserService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class EnrollmentReconciliationController extends Controller
{
    public function __construct(
        private MmrParserService $mmrParser,
        private TrrParserService $trrParser,
        private EnrollmentReconciliationService $reconciliation,
    ) {}

    private function gate(Request $request): void
    {
        $u = $request->user();
        abort_unless($u, 401);
        $dept = $u->department;
        $canAccess = $u->isSuperAdmin()
            || in_array($dept, ['finance', 'qa_compliance', 'it_admin'], true)
            || ($dept === 'enrollment' && $u->isAdmin());
        abort_unless($canAccess, 403, 'CMS reconciliation requires finance / QA / IT Admin / enrollment admin.');
    }

    public function index(Request $request): InertiaResponse
    {
        $this->gate($request);
        $tenantId = $request->user()->effectiveTenantId();

        $mmrFiles = MmrFile::where('tenant_id', $tenantId)
            ->with('uploadedBy:id,first_name,last_name')
            ->orderByDesc('period_year')
            ->orderByDesc('period_month')
            ->orderByDesc('received_at')
            ->limit(24)
            ->get()
            ->map(fn (MmrFile $f) => [
                'id'                       => $f->id,
                'label'                    => $f->label(),
                'period_year'              => $f->period_year,
                'period_month'             => $f->period_month,
                'original_filename'        => $f->original_filename,
                'status'                   => $f->status,
                'received_at'              => $f->received_at?->toIso8601String(),
                'parsed_at'                => $f->parsed_at?->toIso8601String(),
                'uploaded_by'              => $f->uploadedBy
                    ? $f->uploadedBy->first_name . ' ' . $f->uploadedBy->last_name
                    : null,
                'record_count'             => $f->record_count,
                'discrepancy_count'        => $f->discrepancy_count,
                'total_capitation_amount'  => (float) $f->total_capitation_amount,
                'parse_error_message'      => $f->parse_error_message,
            ]);

        $trrFiles = TrrFile::where('tenant_id', $tenantId)
            ->with('uploadedBy:id,first_name,last_name')
            ->orderByDesc('received_at')
            ->limit(24)
            ->get()
            ->map(fn (TrrFile $f) => [
                'id'                => $f->id,
                'original_filename' => $f->original_filename,
                'status'            => $f->status,
                'received_at'       => $f->received_at?->toIso8601String(),
                'parsed_at'         => $f->parsed_at?->toIso8601String(),
                'uploaded_by'       => $f->uploadedBy
                    ? $f->uploadedBy->first_name . ' ' . $f->uploadedBy->last_name
                    : null,
                'record_count'      => $f->record_count,
                'accepted_count'    => $f->accepted_count,
                'rejected_count'    => $f->rejected_count,
                'parse_error_message' => $f->parse_error_message,
            ]);

        $openDiscrepancies = MmrRecord::forTenant($tenantId)
            ->openDiscrepancies()
            ->with('participant:id,mrn,first_name,last_name', 'file:id,period_year,period_month')
            ->orderByDesc('created_at')
            ->limit(200)
            ->get()
            ->map(fn (MmrRecord $r) => [
                'id'                => $r->id,
                'discrepancy_type'  => $r->discrepancy_type,
                'discrepancy_label' => MmrRecord::DISC_LABELS[$r->discrepancy_type] ?? $r->discrepancy_type,
                'discrepancy_note'  => $r->discrepancy_note,
                'medicare_id'       => $r->medicare_id,
                'member_name'       => $r->member_name,
                'capitation_amount' => (float) $r->capitation_amount,
                'adjustment_amount' => (float) $r->adjustment_amount,
                'period'            => $r->file ? sprintf('%04d-%02d', $r->file->period_year, $r->file->period_month) : null,
                'participant'       => $r->participant ? [
                    'id'   => $r->participant->id,
                    'mrn'  => $r->participant->mrn,
                    'name' => $r->participant->first_name . ' ' . $r->participant->last_name,
                ] : null,
            ]);

        return Inertia::render('Billing/Reconciliation', [
            'mmrFiles'          => $mmrFiles,
            'trrFiles'          => $trrFiles,
            'openDiscrepancies' => $openDiscrepancies,
            'discrepancyLabels' => MmrRecord::DISC_LABELS,
        ]);
    }

    /** GET JSON summary for a single MMR file : used by expand row. */
    public function showMmrFile(Request $request, MmrFile $file): JsonResponse
    {
        $this->gate($request);
        abort_if($file->tenant_id !== $request->user()->effectiveTenantId(), 403);
        return response()->json([
            'file'    => $file,
            'summary' => $this->reconciliation->reconciliationSummary($file),
        ]);
    }

    public function uploadMmr(Request $request): JsonResponse
    {
        $this->gate($request);
        $v = $request->validate([
            'period_year'  => ['required', 'integer', 'min:2000', 'max:2100'],
            'period_month' => ['required', 'integer', 'min:1', 'max:12'],
            'file'         => ['required', 'file', 'max:20480', 'mimetypes:text/plain,application/octet-stream'],
        ]);

        $user = $request->user();
        $upload = $request->file('file');
        $storagePath = $upload->store("mmr/{$user->effectiveTenantId()}", 'local');

        $file = MmrFile::create([
            'tenant_id'           => $user->effectiveTenantId(),
            'uploaded_by_user_id' => $user->id,
            'period_year'         => (int) $v['period_year'],
            'period_month'        => (int) $v['period_month'],
            'original_filename'   => $upload->getClientOriginalName(),
            'storage_path'        => $storagePath,
            'file_size_bytes'     => Storage::disk('local')->size($storagePath),
            'received_at'         => now(),
            'status'              => MmrFile::STATUS_RECEIVED,
        ]);

        $this->mmrParser->parse($file, $user);

        AuditLog::record(
            action:       'mmr_file.uploaded',
            tenantId:     $user->tenant_id,
            userId:       $user->id,
            resourceType: 'mmr_file',
            resourceId:   $file->id,
            description:  "Uploaded MMR for {$file->label()}",
        );

        return response()->json($file->fresh(), 201);
    }

    public function uploadTrr(Request $request): JsonResponse
    {
        $this->gate($request);
        $v = $request->validate([
            'file' => ['required', 'file', 'max:20480', 'mimetypes:text/plain,application/octet-stream'],
        ]);

        $user = $request->user();
        $upload = $request->file('file');
        $storagePath = $upload->store("trr/{$user->effectiveTenantId()}", 'local');

        $file = TrrFile::create([
            'tenant_id'           => $user->effectiveTenantId(),
            'uploaded_by_user_id' => $user->id,
            'original_filename'   => $upload->getClientOriginalName(),
            'storage_path'        => $storagePath,
            'file_size_bytes'     => Storage::disk('local')->size($storagePath),
            'received_at'         => now(),
            'status'              => TrrFile::STATUS_RECEIVED,
        ]);

        $this->trrParser->parse($file, $user);

        AuditLog::record(
            action:       'trr_file.uploaded',
            tenantId:     $user->tenant_id,
            userId:       $user->id,
            resourceType: 'trr_file',
            resourceId:   $file->id,
            description:  "Uploaded TRR: {$file->original_filename}",
        );

        return response()->json($file->fresh(), 201);
    }

    public function resolveDiscrepancy(Request $request, MmrRecord $record): JsonResponse
    {
        $this->gate($request);
        abort_if($record->tenant_id !== $request->user()->effectiveTenantId(), 403);

        $v = $request->validate([
            'action' => ['required', 'in:resolved,ignored'],
            'notes'  => ['nullable', 'string', 'max:4000'],
        ]);

        $record->update([
            'resolution_status'   => $v['action'],
            'resolved_at'         => now(),
            'resolved_by_user_id' => $request->user()->id,
            'resolution_notes'    => $v['notes'] ?? null,
        ]);

        AuditLog::record(
            action:       'mmr_discrepancy.resolved',
            tenantId:     $request->user()->tenant_id,
            userId:       $request->user()->id,
            resourceType: 'mmr_record',
            resourceId:   $record->id,
            description:  "Discrepancy {$record->discrepancy_type} marked {$v['action']}",
        );

        return response()->json($record->fresh());
    }
}
