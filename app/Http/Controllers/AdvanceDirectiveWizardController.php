<?php

// ─── AdvanceDirectiveWizardController : Phase M1 ────────────────────────────
// Wizard-style AD capture: structured choices JSON + signature via ConsentRecord.
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\ConsentRecord;
use App\Models\Participant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AdvanceDirectiveWizardController extends Controller
{
    public const AD_TYPES = ['dnr', 'polst', 'molst', 'healthcare_proxy', 'living_will', 'combined'];

    private function gate(): void
    {
        $u = Auth::user();
        abort_if(! $u, 401);
        $allow = ['primary_care', 'home_care', 'social_work', 'qa_compliance', 'it_admin', 'enrollment'];
        abort_unless($u->isSuperAdmin() || in_array($u->department, $allow, true), 403);
    }

    /** POST /participants/{p}/advance-directive : creates + signs the AD. */
    public function store(Request $request, Participant $participant): JsonResponse
    {
        $this->gate();
        $u = Auth::user();
        abort_if($participant->tenant_id !== $u->effectiveTenantId(), 403);

        $validated = $request->validate([
            'ad_type'           => 'required|in:' . implode(',', self::AD_TYPES),
            'choices'           => 'required|array',
            'choices.code_status'     => 'nullable|string',
            'choices.intubation'      => 'nullable|string',
            'choices.artificial_nutrition' => 'nullable|string',
            'choices.antibiotics'     => 'nullable|string',
            'choices.comfort_only'    => 'nullable|boolean',
            'signature_data_url' => 'required|string|starts_with:data:image/png;base64,',
            'proxy_signer_name'  => 'nullable|string|max:200',
            'proxy_relationship' => 'nullable|string|max:100',
            'representative_type'=> 'nullable|in:' . implode(',', ConsentRecord::REPRESENTATIVE_TYPES),
        ]);

        $hasProxy = ! empty($validated['proxy_signer_name']);
        if ($hasProxy !== !empty($validated['proxy_relationship'])) {
            return response()->json([
                'error' => 'proxy_info_incomplete',
                'message' => 'Both proxy_signer_name and proxy_relationship are required together.',
            ], 422);
        }

        $consent = ConsentRecord::create([
            'tenant_id'          => $participant->tenant_id,
            'participant_id'     => $participant->id,
            'consent_type'       => 'advance_directive',
            'document_title'     => 'Advance Directive : ' . strtoupper($validated['ad_type']),
            'document_version'   => '1.0',
            'status'             => 'acknowledged',
            'representative_type'=> $validated['representative_type'] ?? ($hasProxy ? 'healthcare_proxy' : 'self'),
            'notes'              => json_encode($validated['choices']),
            'signature_image_blob'     => $validated['signature_data_url'],
            'signed_by_participant'    => ! $hasProxy,
            'proxy_signer_name'        => $validated['proxy_signer_name']  ?? null,
            'proxy_relationship'       => $validated['proxy_relationship'] ?? null,
            'signed_ip_address'        => $request->ip(),
            'esign_disclaimer_version' => ConsentRecord::ESIGN_DISCLAIMER_VERSION,
            'signed_at'                => now(),
            'acknowledged_at'          => now(),
            'acknowledged_by'          => $hasProxy
                ? $validated['proxy_signer_name']
                : $participant->first_name . ' ' . $participant->last_name,
            'created_by_user_id'       => $u->id,
        ]);

        $participant->update([
            'advance_directive_type'     => $validated['ad_type'],
            'advance_directive_status'   => 'has_directive',
            'advance_directive_reviewed_at' => now()->toDateString(),
            'advance_directive_reviewed_by_user_id' => $u->id,
        ]);

        AuditLog::record(
            action:       'advance_directive.signed',
            tenantId:     $participant->tenant_id,
            userId:       $u->id,
            resourceType: 'consent_record',
            resourceId:   $consent->id,
            description:  "Advance Directive ({$validated['ad_type']}) signed"
                . ($hasProxy ? " by proxy ({$validated['proxy_relationship']})" : ' by participant')
                . '. Disclaimer ' . ConsentRecord::ESIGN_DISCLAIMER_VERSION . '.',
        );

        return response()->json(['consent' => $consent->fresh()], 201);
    }
}
