<?php

// ─── StateMedicaidSubmissionController ──────────────────────────────────────
// Phase 15.9 : Scaffold controller for per-state Medicaid encounter
// submission. Default adapter stages the payload and records the submission
// as status='staged_manual' for the operator to upload to the state portal.
// Real transmission per state is a future activation.
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\EdiBatch;
use App\Models\StateMedicaidConfig;
use App\Models\StateMedicaidSubmission;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class StateMedicaidSubmissionController extends Controller
{
    private function gate(): void
    {
        $u = Auth::user();
        abort_if(!$u, 401);
        abort_unless(
            $u->isSuperAdmin() || in_array($u->department, ['finance', 'qa_compliance', 'it_admin']),
            403
        );
    }

    public function stageForState(Request $request, EdiBatch $batch, string $stateCode): JsonResponse
    {
        $this->gate();
        $u = Auth::user();
        abort_unless($batch->tenant_id === $u->tenant_id, 404);
        $state = strtoupper($stateCode);
        abort_unless(strlen($state) === 2, 422, 'state_code must be 2 letters.');

        $config = StateMedicaidConfig::forTenant($u->tenant_id)
            ->where('state_code', $state)
            ->active()->first();

        // Phase M6 : if a per-state adapter exists, transform the payload.
        $rawPayload = $batch->file_content ?? $batch->edi_content ?? '';
        $adapter = \App\Services\StateMedicaid\StateAdapterFactory::for($state);
        $payload = $adapter
            ? $adapter->transform($rawPayload, ['tenant_id' => $u->tenant_id, 'batch_id' => $batch->id])
            : $rawPayload;
        $format = $adapter?->format() ?? ($config?->submission_format ?: '837P');

        $submission = StateMedicaidSubmission::create([
            'tenant_id'        => $u->tenant_id,
            'state_config_id'  => $config?->id,
            'edi_batch_id'     => $batch->id,
            'state_code'       => $state,
            'submission_format'=> $format,
            'status'           => 'staged_manual',
            'payload_text'     => $payload,
            'response_notes'   => $adapter
                ? "Transformed via {$format} adapter. Awaiting manual portal upload."
                : 'Scaffold : no per-state adapter for this state. Payload staged for manual portal upload.',
            'prepared_by_user_id' => $u->id,
        ]);

        AuditLog::record(
            action: 'state_medicaid.submission_staged',
            tenantId: $u->tenant_id, userId: $u->id,
            resourceType: 'state_medicaid_submission',
            resourceId: $submission->id,
            description: "State Medicaid submission staged: {$state} · batch {$batch->id}",
        );

        return response()->json([
            'submission'   => $submission,
            'honest_label' => 'State Medicaid encounter transmission is scaffold-only. The payload is staged here for manual portal upload; activation requires a per-state adapter (CA MEDS, NY eMedNY, FL MMIS, etc.) and credentials : all gated on vendor/state contracts.',
        ], 201);
    }

    public const HONEST_BANNER = 'State Medicaid encounter transmission is scaffold-only. Payloads are staged here for manual portal upload; real per-state transmission requires a state portal contract + credentials. See paywall report item 8.';

    public function index(Request $request): JsonResponse|\Inertia\Response
    {
        $this->gate();
        $u = Auth::user();
        $submissions = StateMedicaidSubmission::forTenant($u->tenant_id)
            ->orderByDesc('created_at')->limit(100)->get();

        // Phase O11 : dual-serve so the banner is actually visible on the
        // existing /state-medicaid/submissions URL when navigated to in a browser.
        if (! $request->wantsJson()) {
            return \Inertia\Inertia::render('Operations/StateMedicaidSubmissions', [
                'submissions' => $submissions,
                'banner'      => self::HONEST_BANNER,
            ]);
        }

        return response()->json([
            'submissions' => $submissions,
            'banner'      => self::HONEST_BANNER,
        ]);
    }
}
