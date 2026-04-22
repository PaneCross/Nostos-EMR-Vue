<?php

// ─── ImmunizationSubmissionController ────────────────────────────────────────
// Phase 8 (MVP roadmap). Generates an HL7 VXU message for a single
// immunization and records it as an ImmunizationSubmission row. Does NOT
// actually transmit to a state IIS — honest-labeled. The response includes
// the rendered VXU text so staff can download/inspect it.
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Immunization;
use App\Models\ImmunizationSubmission;
use App\Models\Participant;
use App\Models\StateImmunizationRegistryConfig;
use App\Services\Hl7VxuBuilder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ImmunizationSubmissionController extends Controller
{
    public function __construct(private Hl7VxuBuilder $builder) {}

    private function gate(): void
    {
        $u = Auth::user();
        abort_if(!$u, 401);
        $ok = $u->isSuperAdmin()
            || in_array($u->department, ['primary_care', 'nursing', 'qa_compliance', 'it_admin']);
        abort_unless($ok, 403);
    }

    public function store(Request $request, Participant $participant, Immunization $immunization)
    {
        $this->gate();
        $u = Auth::user();
        abort_unless($participant->tenant_id === $u->tenant_id, 404);
        abort_unless($immunization->participant_id === $participant->id, 404);

        $validated = $request->validate([
            'state_code' => 'required|string|size:2',
        ]);
        $state = strtoupper($validated['state_code']);

        $cfg = StateImmunizationRegistryConfig::forTenant($u->tenant_id)
            ->where('state_code', $state)
            ->active()
            ->first();

        $build = $this->builder->build($participant, $immunization, $cfg);

        $row = ImmunizationSubmission::create([
            'tenant_id'            => $u->tenant_id,
            'participant_id'       => $participant->id,
            'immunization_id'      => $immunization->id,
            'state_code'           => $state,
            'message_control_id'   => $build['message_control_id'],
            'vxu_message'          => $build['message'],
            'status'               => 'submitted', // honest-label: "simulated" submission
            'submitted_at'         => now(),
            'generated_by_user_id' => $u->id,
        ]);

        AuditLog::record(
            action: 'immunization.vxu_simulated_submit',
            tenantId: $u->tenant_id,
            userId: $u->id,
            resourceType: 'immunization',
            resourceId: $immunization->id,
            description: "VXU simulated submit to state IIS: {$state}",
            newValues: ['state' => $state, 'submission_id' => $row->id, 'message_control_id' => $row->message_control_id]
        );

        return response()->json([
            'submission' => [
                'id'                 => $row->id,
                'state_code'         => $row->state_code,
                'status'             => $row->status,
                'submitted_at'       => $row->submitted_at?->toIso8601String(),
                'message_control_id' => $row->message_control_id,
                'vxu_message'        => $row->vxu_message,
                'honest_label'       => 'Simulated submission — no actual transmission to state IIS.',
            ],
        ], 201);
    }

    public function index(Request $request, Participant $participant)
    {
        $this->gate();
        $u = Auth::user();
        abort_unless($participant->tenant_id === $u->tenant_id, 404);

        $rows = ImmunizationSubmission::forTenant($u->tenant_id)
            ->where('participant_id', $participant->id)
            ->orderByDesc('submitted_at')
            ->limit(50)
            ->get(['id', 'immunization_id', 'state_code', 'status', 'submitted_at', 'message_control_id']);

        return response()->json(['submissions' => $rows]);
    }
}
