<?php

// ─── StaffCredentialController ────────────────────────────────────────────────
// Credentials + training-record CRUD on a single staff user.
//
// Routes:
//   GET  /it-admin/users/{user}/credentials      : Inertia page
//   POST /it-admin/users/{user}/credentials      : add credential
//   PATCH /staff-credentials/{credential}        : update credential
//   DELETE /staff-credentials/{credential}       : soft-delete
//   POST /it-admin/users/{user}/training         : add training record
//   DELETE /staff-training/{record}              : soft-delete
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Http\Controllers;

use App\Models\StaffCredential;
use App\Models\StaffTrainingRecord;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class StaffCredentialController extends Controller
{
    /** Gate: IT admin, super admin, QA compliance (audit/review). */
    private function gate(Request $request): void
    {
        $u = $request->user();
        abort_unless($u, 401);
        abort_unless(
            $u->isSuperAdmin()
                || in_array($u->department, ['it_admin', 'qa_compliance'], true),
            403,
            'Only IT Admin, QA Compliance, and Super Admin may manage staff credentials.'
        );
    }

    /** Scope: same tenant as the acting user. */
    private function assertSameTenant(User $staff, Request $request): void
    {
        abort_if($staff->tenant_id !== $request->user()->tenant_id, 403);
    }

    public function index(Request $request, User $user): InertiaResponse
    {
        $this->gate($request);
        $this->assertSameTenant($user, $request);

        $credentials = StaffCredential::forTenant($user->tenant_id)
            ->where('user_id', $user->id)
            ->orderByRaw('expires_at IS NULL ASC, expires_at ASC')
            ->get()
            ->map(fn (StaffCredential $c) => [
                'id'               => $c->id,
                'credential_type'  => $c->credential_type,
                'type_label'       => StaffCredential::TYPE_LABELS[$c->credential_type] ?? $c->credential_type,
                'title'            => $c->title,
                'license_state'    => $c->license_state,
                'license_number'   => $c->license_number,
                'issued_at'        => $c->issued_at?->toDateString(),
                'expires_at'       => $c->expires_at?->toDateString(),
                'days_remaining'   => $c->daysUntilExpiration(),
                'status'           => $c->status(),
                'verified_at'      => $c->verified_at?->toIso8601String(),
                'notes'            => $c->notes,
            ]);

        $training = StaffTrainingRecord::forTenant($user->tenant_id)
            ->where('user_id', $user->id)
            ->orderByDesc('completed_at')
            ->get()
            ->map(fn (StaffTrainingRecord $r) => [
                'id'             => $r->id,
                'training_name'  => $r->training_name,
                'category'       => $r->category,
                'category_label' => StaffTrainingRecord::CATEGORY_LABELS[$r->category] ?? $r->category,
                'training_hours' => (float) $r->training_hours,
                'completed_at'   => $r->completed_at?->toDateString(),
                'verified_at'    => $r->verified_at?->toIso8601String(),
                'notes'          => $r->notes,
            ]);

        // Training hours totals: past 12 months, grouped by category.
        $since = Carbon::now()->subYear()->toDateString();
        $hoursByCategory = StaffTrainingRecord::forTenant($user->tenant_id)
            ->where('user_id', $user->id)
            ->where('completed_at', '>=', $since)
            ->selectRaw('category, SUM(training_hours) as total_hours')
            ->groupBy('category')
            ->pluck('total_hours', 'category')
            ->map(fn ($v) => (float) $v)
            ->toArray();

        $totalHours = (float) array_sum($hoursByCategory);

        return Inertia::render('ItAdmin/StaffCredentials', [
            'staff' => [
                'id'         => $user->id,
                'first_name' => $user->first_name,
                'last_name'  => $user->last_name,
                'email'      => $user->email,
                'department' => $user->department,
                'role'       => $user->role,
                'is_active'  => (bool) $user->is_active,
            ],
            'credentials'     => $credentials,
            'training'        => $training,
            'hoursByCategory' => $hoursByCategory,
            'totalHours12mo'  => round($totalHours, 2),
            'credentialTypes' => StaffCredential::TYPE_LABELS,
            'trainingCategories' => StaffTrainingRecord::CATEGORY_LABELS,
        ]);
    }

    public function storeCredential(Request $request, User $user): JsonResponse
    {
        $this->gate($request);
        $this->assertSameTenant($user, $request);

        $v = $request->validate([
            'credential_type' => ['required', Rule::in(StaffCredential::TYPES)],
            'title'           => ['required', 'string', 'max:200'],
            'license_state'   => ['nullable', 'string', 'size:2'],
            'license_number'  => ['nullable', 'string', 'max:80'],
            'issued_at'       => ['nullable', 'date'],
            'expires_at'      => ['nullable', 'date', 'after_or_equal:issued_at'],
            'notes'           => ['nullable', 'string', 'max:4000'],
        ]);

        $c = StaffCredential::create(array_merge($v, [
            'tenant_id' => $user->tenant_id,
            'user_id'   => $user->id,
            'verified_at'         => now(),
            'verified_by_user_id' => $request->user()->id,
        ]));

        return response()->json($c, 201);
    }

    public function updateCredential(Request $request, StaffCredential $credential): JsonResponse
    {
        $this->gate($request);
        abort_if($credential->tenant_id !== $request->user()->tenant_id, 403);

        $v = $request->validate([
            'title'          => ['sometimes', 'string', 'max:200'],
            'license_state'  => ['nullable', 'string', 'size:2'],
            'license_number' => ['nullable', 'string', 'max:80'],
            'issued_at'      => ['nullable', 'date'],
            'expires_at'     => ['nullable', 'date'],
            'notes'          => ['nullable', 'string', 'max:4000'],
        ]);

        $credential->update($v);
        return response()->json($credential->fresh());
    }

    public function destroyCredential(Request $request, StaffCredential $credential): JsonResponse
    {
        $this->gate($request);
        abort_if($credential->tenant_id !== $request->user()->tenant_id, 403);
        $credential->delete();
        return response()->json(['ok' => true]);
    }

    public function storeTraining(Request $request, User $user): JsonResponse
    {
        $this->gate($request);
        $this->assertSameTenant($user, $request);

        $v = $request->validate([
            'training_name'  => ['required', 'string', 'max:200'],
            'category'       => ['required', Rule::in(StaffTrainingRecord::CATEGORIES)],
            'training_hours' => ['required', 'numeric', 'min:0', 'max:99'],
            'completed_at'   => ['required', 'date', 'before_or_equal:today'],
            'notes'          => ['nullable', 'string', 'max:4000'],
        ]);

        $r = StaffTrainingRecord::create(array_merge($v, [
            'tenant_id' => $user->tenant_id,
            'user_id'   => $user->id,
            'verified_at'         => now(),
            'verified_by_user_id' => $request->user()->id,
        ]));

        return response()->json($r, 201);
    }

    public function destroyTraining(Request $request, StaffTrainingRecord $record): JsonResponse
    {
        $this->gate($request);
        abort_if($record->tenant_id !== $request->user()->tenant_id, 403);
        $record->delete();
        return response()->json(['ok' => true]);
    }
}
