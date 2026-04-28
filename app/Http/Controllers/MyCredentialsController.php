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

        $v = $request->validate([
            'issued_at'  => ['nullable', 'date'],
            'expires_at' => ['nullable', 'date', 'after_or_equal:issued_at'],
            'document'   => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:10240'],
        ]);

        // Save new doc, advance dates, set verification_source to self_attestation
        // so admin knows to re-verify.
        $ext = strtolower($v['document']->getClientOriginalExtension());
        $dir = "credentials/tenant_{$credential->tenant_id}/user_{$credential->user_id}";
        $name = "cred_{$credential->id}_renewal_" . now()->format('YmdHis') . ".{$ext}";
        $path = $v['document']->storeAs($dir, $name, 'local');

        $old = $credential->toArray();
        $credential->update([
            'document_path'       => $path,
            'document_filename'   => $v['document']->getClientOriginalName(),
            'issued_at'           => $v['issued_at'] ?? $credential->issued_at,
            'expires_at'          => $v['expires_at'] ?? $credential->expires_at,
            'verification_source' => 'self_attestation',
            'cms_status'          => 'pending',  // admin must re-verify before going active
            'verified_at'         => null,
            'verified_by_user_id' => null,
        ]);

        AuditLog::record(
            action: 'staff_credential.renewal_uploaded',
            resourceType: 'StaffCredential',
            resourceId: $credential->id,
            tenantId: $credential->tenant_id,
            userId: $user->id,
            oldValues: $old,
            newValues: $credential->fresh()->toArray(),
        );

        return response()->json([
            'ok' => true,
            'message' => 'Renewal uploaded. IT Admin will verify and mark active.',
            'credential' => $credential->fresh(),
        ]);
    }
}
