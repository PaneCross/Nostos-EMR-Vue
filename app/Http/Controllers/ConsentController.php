<?php

// ─── ConsentController ────────────────────────────────────────────────────────
// JSON API for participant consent and acknowledgment records.
// Used by the Consents tab in Participants/Show.tsx.
//
// HIPAA 45 CFR §164.520: Providers must make good-faith effort to obtain
// written acknowledgment of NPP receipt at first service delivery.
// NostosEMR auto-creates a pending npp_acknowledgment record at enrollment;
// this controller handles recording the actual acknowledgment.
//
// Routes (all under /participants/{participant}/):
//   GET  /consents          → index (list all consents for participant)
//   POST /consents          → store (create consent record)
//   PUT  /consents/{cid}    → update (record acknowledgment / update status)
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Http\Controllers;

use App\Http\Requests\StoreConsentRequest;
use App\Http\Requests\UpdateConsentRequest;
use App\Models\AuditLog;
use App\Models\ConsentRecord;
use App\Models\Participant;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;

class ConsentController extends Controller
{
    // ── Authorization ─────────────────────────────────────────────────────────

    /** Abort 403 if participant does not belong to the user's tenant */
    private function authorizeParticipant(Participant $participant): void
    {
        abort_if($participant->tenant_id !== Auth::user()->tenant_id, 403);
    }

    /** Abort 403 if consent does not belong to the given participant */
    private function authorizeConsent(ConsentRecord $consent, Participant $participant): void
    {
        abort_if($consent->participant_id !== $participant->id, 403);
        abort_if($consent->tenant_id !== Auth::user()->tenant_id, 403);
    }

    // ── Endpoints ─────────────────────────────────────────────────────────────

    /**
     * List all consent records for a participant.
     * Returns JSON for the Consents tab in Participants/Show.tsx.
     */
    public function index(Participant $participant): JsonResponse
    {
        $this->authorizeParticipant($participant);

        $consents = ConsentRecord::forParticipant($participant->id)
            ->with('createdBy:id,first_name,last_name')
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'consents' => $consents->map->toApiArray()->values(),
        ]);
    }

    /**
     * Create a new consent record for a participant.
     * Typically used to add ad-hoc consent types beyond the auto-created NPP.
     */
    public function store(StoreConsentRequest $request, Participant $participant): JsonResponse
    {
        $this->authorizeParticipant($participant);

        $consent = ConsentRecord::create(array_merge($request->validated(), [
            'participant_id'      => $participant->id,
            'tenant_id'           => $participant->tenant_id,
            'created_by_user_id'  => Auth::id(),
        ]));

        AuditLog::record(
            action:       'consent.created',
            tenantId:     $participant->tenant_id,
            userId:       Auth::id(),
            resourceType: 'consent_record',
            resourceId:   $consent->id,
            description:  "Consent record '{$consent->consent_type}' created for participant #{$participant->id}.",
        );

        return response()->json(['consent' => $consent->fresh()->toApiArray()], 201);
    }

    /**
     * Update a consent record — typically to record acknowledgment or refusal.
     * Requires enrollment, qa_compliance, or it_admin department.
     */
    public function update(UpdateConsentRequest $request, Participant $participant, ConsentRecord $consent): JsonResponse
    {
        $this->authorizeParticipant($participant);
        $this->authorizeConsent($consent, $participant);

        // Only enrollment, QA admin, or IT admin may update consent records
        $dept = Auth::user()->department;
        abort_unless(
            in_array($dept, ['enrollment', 'qa_compliance', 'it_admin']) || Auth::user()->isSuperAdmin(),
            403
        );

        $consent->update($request->validated());

        AuditLog::record(
            action:       'consent.updated',
            tenantId:     $participant->tenant_id,
            userId:       Auth::id(),
            resourceType: 'consent_record',
            resourceId:   $consent->id,
            description:  "Consent record '{$consent->consent_type}' updated to status '{$consent->status}'.",
        );

        return response()->json(['consent' => $consent->fresh()->toApiArray()]);
    }

    /**
     * Phase B8a — E-signature capture. Accepts a data-URL PNG signature,
     * optional proxy info, captures IP, stamps signed_at + disclaimer
     * version, and transitions status to 'acknowledged'.
     *
     * Body:
     *   signature_data_url  required  data:image/png;base64,…
     *   proxy_signer_name   nullable  (required if signing on participant's behalf)
     *   proxy_relationship  nullable  e.g. "Daughter (POA)"
     *   representative_type nullable  self|guardian|poa|healthcare_proxy
     *
     * POST /participants/{participant}/consents/{consent}/sign
     */
    public function sign(Request $request, Participant $participant, ConsentRecord $consent): JsonResponse
    {
        $this->authorizeParticipant($participant);
        $this->authorizeConsent($consent, $participant);

        if ($consent->isSigned()) {
            return response()->json(['message' => 'Consent already signed.'], 409);
        }

        $validated = $request->validate([
            'signature_data_url'  => 'required|string|starts_with:data:image/png;base64,',
            'proxy_signer_name'   => 'nullable|string|max:200',
            'proxy_relationship'  => 'nullable|string|max:100',
            'representative_type' => 'nullable|in:' . implode(',', ConsentRecord::REPRESENTATIVE_TYPES),
            'acknowledged_by'     => 'nullable|string|max:200',
        ]);

        // Legal-representative flow: require both proxy fields together.
        $hasProxyName = ! empty($validated['proxy_signer_name']);
        $hasProxyRel  = ! empty($validated['proxy_relationship']);
        if ($hasProxyName !== $hasProxyRel) {
            return response()->json([
                'error'   => 'proxy_info_incomplete',
                'message' => 'proxy_signer_name and proxy_relationship must both be provided when signing by proxy.',
            ], 422);
        }

        $signedByParticipant = ! $hasProxyName;

        $consent->update([
            'signature_image_blob'      => $validated['signature_data_url'],
            'signed_by_participant'     => $signedByParticipant,
            'proxy_signer_name'         => $validated['proxy_signer_name']  ?? null,
            'proxy_relationship'        => $validated['proxy_relationship'] ?? null,
            'representative_type'       => $validated['representative_type']
                ?? ($signedByParticipant ? 'self' : $consent->representative_type),
            'signed_ip_address'         => $request->ip(),
            'esign_disclaimer_version'  => ConsentRecord::ESIGN_DISCLAIMER_VERSION,
            'signed_at'                 => now(),
            'status'                    => 'acknowledged',
            'acknowledged_at'           => now(),
            'acknowledged_by'           => $validated['acknowledged_by']
                ?? ($signedByParticipant
                    ? $participant->first_name . ' ' . $participant->last_name
                    : $validated['proxy_signer_name']),
        ]);

        AuditLog::record(
            action:       'consent.signed',
            tenantId:     $participant->tenant_id,
            userId:       Auth::id(),
            resourceType: 'consent_record',
            resourceId:   $consent->id,
            description:  "Consent '{$consent->consent_type}' e-signed "
                . ($signedByParticipant ? 'by participant' : "by proxy ({$validated['proxy_relationship']})")
                . ". Disclaimer version: " . ConsentRecord::ESIGN_DISCLAIMER_VERSION . '.',
            newValues:    ['ip' => $request->ip(), 'disclaimer_version' => ConsentRecord::ESIGN_DISCLAIMER_VERSION],
        );

        return response()->json(['consent' => $consent->fresh()->toApiArray()]);
    }

    /**
     * Phase B8a — PDF of a signed consent with embedded audit stamp
     * (signed-at, IP, disclaimer version, signer, proxy info if applicable).
     *
     * GET /participants/{participant}/consents/{consent}/signed.pdf
     */
    public function signedPdf(Request $request, Participant $participant, ConsentRecord $consent): Response
    {
        $this->authorizeParticipant($participant);
        $this->authorizeConsent($consent, $participant);
        abort_unless($consent->isSigned(), 404, 'Consent is not signed.');

        $pdf = Pdf::loadView('pdfs.signed-consent', [
            'participant' => $participant,
            'consent'     => $consent,
            'signature'   => $consent->signature_image_blob, // decrypted via cast
        ])->setPaper('letter', 'portrait');

        return $pdf->stream("consent-{$consent->consent_type}-{$participant->mrn}.pdf");
    }
}
