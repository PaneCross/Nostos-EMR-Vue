<?php

// ─── MyCredentialsController ─────────────────────────────────────────────────
// Self-service view + renewal upload for the currently authenticated user.
// Read-only credential list with status badges + missing-required gaps. Users
// can upload a renewal PDF to bump their existing credential ; the upload is
// silently flagged for IT Admin review (verification_source='self_attestation').
//
// Routes:
//   GET  /my-credentials                          : Inertia page
//   POST /my-credentials/{credential}/renewal     : upload renewal PDF + new dates
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\StaffCredential;
use App\Services\Credentials\CredentialDefinitionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class MyCredentialsController extends Controller
{
    public function index(Request $request): InertiaResponse
    {
        $user = $request->user();
        abort_unless($user, 401);

        $credentials = StaffCredential::forTenant($user->tenant_id)
            ->where('user_id', $user->id)
            ->with('definition:id,ceu_hours_required')
            ->orderByRaw('expires_at IS NULL ASC, expires_at ASC')
            ->get()
            ->map(fn (StaffCredential $c) => [
                'id'              => $c->id,
                'credential_definition_id' => $c->credential_definition_id,
                'credential_type' => $c->credential_type,
                'type_label'      => StaffCredential::TYPE_LABELS[$c->credential_type] ?? $c->credential_type,
                'title'           => $c->title,
                'license_state'   => $c->license_state,
                'license_number'  => $c->license_number,
                'issued_at'       => $c->issued_at?->toDateString(),
                'expires_at'      => $c->expires_at?->toDateString(),
                'days_remaining'  => $c->daysUntilExpiration(),
                'status'          => $c->status(),
                'cms_status'      => $c->cms_status,
                'has_document'    => (bool) $c->document_path,
                'document_filename' => $c->document_filename,
                'is_superseded'   => $c->replaced_by_credential_id !== null,
                // V2 : let the user see their own CEU progress per credential
                'ceu_hours_logged'   => $c->ceuHoursLogged(),
                'ceu_hours_required' => (int) ($c->definition?->ceu_hours_required ?? 0),
            ]);

        $defService = app(CredentialDefinitionService::class);
        $missing    = $defService->missingForUser($user)->map(fn ($d) => [
            'id'               => $d->id,
            'title'            => $d->title,
            'is_cms_mandatory' => $d->is_cms_mandatory,
            'credential_type_label' => StaffCredential::TYPE_LABELS[$d->credential_type] ?? $d->credential_type,
        ])->values();

        return Inertia::render('User/MyCredentials', [
            'credentials' => $credentials,
            'missing'     => $missing,
            'me' => [
                'id'         => $user->id,
                'first_name' => $user->first_name,
                'last_name'  => $user->last_name,
                'department' => $user->department,
                'job_title'  => $user->job_title,
            ],
        ]);
    }

    public function uploadRenewal(Request $request, StaffCredential $credential): JsonResponse|RedirectResponse
    {
        $user = $request->user();
        abort_unless($user && $credential->user_id === $user->id, 403,
            'You can only upload renewals to your own credentials.');
        abort_if($credential->tenant_id !== $user->tenant_id, 403);

        // Reject renewal of an already-superseded row : the user should renew
        // the tip-of-chain row, not a historical entry. Likewise reject if the
        // existing row is already pending (admin hasn't verified the prior
        // renewal yet) so we don't stack pending rows.
        if ($credential->replaced_by_credential_id !== null) {
            return response()->json([
                'message' => 'This credential has already been replaced by a newer record. Renew the current version instead.',
            ], 422);
        }
        if ($credential->cms_status === 'pending') {
            return response()->json([
                'message' => 'A renewal for this credential is already pending IT Admin verification. You will be notified once it is verified.',
            ], 422);
        }

        $v = $request->validate([
            'issued_at'  => ['nullable', 'date'],
            'expires_at' => ['nullable', 'date', 'after_or_equal:issued_at'],
            'document'   => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:10240'],
        ]);

        // V2 renewal versioning : create a NEW credential row instead of mutating
        // the old one in place. Preserves the prior document as an audit-trail
        // artifact (CMS auditors want the chain), and the old row's
        // replaced_by_credential_id points forward to the new row. The "tip of
        // the chain" (no replaced_by) is the user's current version.

        $ext = strtolower($v['document']->getClientOriginalExtension());
        $dir = "credentials/tenant_{$credential->tenant_id}/user_{$credential->user_id}";
        $name = "cred_renewal_" . now()->format('YmdHis') . ".{$ext}";
        $path = $v['document']->storeAs($dir, $name, 'local');

        $newCred = StaffCredential::create([
            'tenant_id'                => $credential->tenant_id,
            'user_id'                  => $credential->user_id,
            'credential_definition_id' => $credential->credential_definition_id,
            'credential_type'          => $credential->credential_type,
            'title'                    => $credential->title,
            'license_state'            => $credential->license_state,
            'license_number'           => $credential->license_number,
            'issued_at'                => $v['issued_at'] ?? $credential->issued_at,
            'expires_at'               => $v['expires_at'] ?? $credential->expires_at,
            'verification_source'      => 'self_attestation',
            'cms_status'               => 'pending',
            'document_path'            => $path,
            'document_filename'        => $v['document']->getClientOriginalName(),
            'dot_medical_card_expires_at' => $credential->dot_medical_card_expires_at,
            'mvr_check_date'              => $credential->mvr_check_date,
            'vehicle_class_endorsements'  => $credential->vehicle_class_endorsements,
            'notes'                    => 'Self-attested renewal awaiting IT Admin verification.',
        ]);

        // Mark the old row as superseded (forward link to new)
        $credential->update(['replaced_by_credential_id' => $newCred->id]);

        AuditLog::record(
            action: 'staff_credential.renewal_uploaded',
            resourceType: 'StaffCredential',
            resourceId: $newCred->id,
            tenantId: $credential->tenant_id,
            userId: $user->id,
            newValues: [
                'new_credential_id' => $newCred->id,
                'replaces_credential_id' => $credential->id,
            ],
        );

        return response()->json([
            'ok' => true,
            'message' => 'Renewal uploaded. The previous record is preserved as audit history. IT Admin will verify and mark the new entry active.',
            'credential' => $newCred->fresh(),
        ]);
    }
}
