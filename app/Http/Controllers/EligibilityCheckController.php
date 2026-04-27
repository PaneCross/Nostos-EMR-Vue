<?php

// ─── EligibilityCheckController : Phase P5 ──────────────────────────────────
namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\EligibilityCheck;
use App\Models\Participant;
use App\Services\Eligibility\AvailityEligibilityGateway;
use App\Services\Eligibility\ChangeHealthcareEligibilityGateway;
use App\Services\Eligibility\EligibilityGateway;
use App\Services\Eligibility\NullEligibilityGateway;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class EligibilityCheckController extends Controller
{
    private function gateway(): EligibilityGateway
    {
        return match (config('services.eligibility.driver', 'null')) {
            'availity'           => new AvailityEligibilityGateway(),
            'change_healthcare'  => new ChangeHealthcareEligibilityGateway(),
            default              => new NullEligibilityGateway(),
        };
    }

    private function gate(): void
    {
        $u = Auth::user();
        abort_if(! $u, 401);
        $allow = ['enrollment', 'finance', 'qa_compliance', 'it_admin', 'primary_care'];
        abort_unless($u->isSuperAdmin() || in_array($u->department, $allow, true), 403);
    }

    public function store(Request $request, Participant $participant): JsonResponse
    {
        $this->gate();
        $u = Auth::user();
        abort_if($participant->tenant_id !== $u->tenant_id, 403);

        $validated = $request->validate([
            'payer_type'        => 'required|in:medicare,medicaid,other',
            'member_id_lookup'  => 'nullable|string|max:60',
        ]);

        $gateway = $this->gateway();
        $result = $gateway->check($participant, $validated['payer_type'], $validated['member_id_lookup'] ?? null);

        $row = EligibilityCheck::create([
            'tenant_id'             => $u->tenant_id,
            'participant_id'        => $participant->id,
            'payer_type'            => $validated['payer_type'],
            'member_id_lookup'      => $validated['member_id_lookup'] ?? null,
            'requested_at'          => now(),
            'response_status'       => $result['status'],
            'response_payload_json' => $result['payload'] ?? null,
            'gateway_used'          => $gateway->name(),
            'requested_by_user_id'  => $u->id,
        ]);

        AuditLog::record(
            action: 'eligibility.checked',
            tenantId: $u->tenant_id,
            userId: $u->id,
            resourceType: 'eligibility_check',
            resourceId: $row->id,
            description: "Eligibility check ({$validated['payer_type']}) → {$result['status']}",
        );

        return response()->json([
            'check'   => $row,
            'gateway' => $gateway->name(),
        ], 201);
    }

    public function index(Request $request, Participant $participant): JsonResponse
    {
        $this->gate();
        $u = Auth::user();
        abort_if($participant->tenant_id !== $u->tenant_id, 403);

        return response()->json([
            'checks' => EligibilityCheck::forTenant($u->tenant_id)
                ->forParticipant($participant->id)
                ->orderByDesc('requested_at')->limit(20)->get(),
        ]);
    }
}
