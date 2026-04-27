<?php

// ─── FormularyController ─────────────────────────────────────────────────────
// Phase 15.10 : Per-tenant formulary CRUD + check + coverage determinations.
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\CoverageDetermination;
use App\Models\FormularyEntry;
use App\Models\Participant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class FormularyController extends Controller
{
    private function gate(array $allow = ['pharmacy', 'primary_care', 'qa_compliance', 'it_admin']): void
    {
        $u = Auth::user();
        abort_if(!$u, 401);
        abort_unless($u->isSuperAdmin() || in_array($u->department, $allow, true), 403);
    }

    public function index(Request $request)
    {
        $this->gate(['pharmacy', 'primary_care', 'therapies', 'qa_compliance', 'it_admin', 'finance']);
        $u = Auth::user();
        $q = FormularyEntry::forTenant($u->tenant_id);
        if ($term = trim((string) $request->query('q', ''))) {
            $like = '%' . $term . '%';
            $q->where(function ($w) use ($like) {
                $w->where('drug_name', 'ilike', $like)
                  ->orWhere('generic_name', 'ilike', $like)
                  ->orWhere('rxnorm_code', 'ilike', $like);
            });
        }
        if ($request->boolean('active_only', true)) $q->active();
        $entries = $q->orderBy('tier')->orderBy('drug_name')->limit(500)->get();

        if ($request->wantsJson()) {
            return response()->json(['entries' => $entries]);
        }

        $pending = \App\Models\CoverageDetermination::forTenant($u->tenant_id)
            ->pending()->with('participant:id,first_name,last_name,mrn')
            ->orderByDesc('requested_at')->limit(50)->get();

        return \Inertia\Inertia::render('Formulary/Index', [
            'entries'        => $entries,
            'pendingDeterminations' => $pending,
            'canEdit'        => $u->isSuperAdmin() || in_array($u->department, ['pharmacy', 'qa_compliance', 'it_admin']),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->gate();
        $u = Auth::user();
        $validated = $request->validate([
            'drug_name'                    => 'required|string|max:200',
            'generic_name'                 => 'nullable|string|max:200',
            'rxnorm_code'                  => 'nullable|string|max:20',
            'tier'                         => 'required|integer|between:1,5',
            'prior_authorization_required' => 'boolean',
            'quantity_limit'               => 'boolean',
            'quantity_limit_text'          => 'nullable|string|max:200',
            'step_therapy_required'        => 'boolean',
            'notes'                        => 'nullable|string|max:2000',
        ]);
        $entry = FormularyEntry::create(array_merge($validated, [
            'tenant_id'         => $u->tenant_id,
            'added_by_user_id'  => $u->id,
            'last_reviewed_at'  => now(),
            'is_active'         => true,
        ]));
        AuditLog::record(
            action: 'formulary.entry_created',
            tenantId: $u->tenant_id, userId: $u->id,
            resourceType: 'formulary_entry', resourceId: $entry->id,
            description: "Formulary entry created: {$entry->drug_name} (tier {$entry->tier})",
        );
        return response()->json(['entry' => $entry], 201);
    }

    public function update(Request $request, FormularyEntry $entry): JsonResponse
    {
        $this->gate();
        $u = Auth::user();
        abort_unless($entry->tenant_id === $u->tenant_id, 404);
        $validated = $request->validate([
            'drug_name'                    => 'sometimes|string|max:200',
            'generic_name'                 => 'nullable|string|max:200',
            'rxnorm_code'                  => 'nullable|string|max:20',
            'tier'                         => 'sometimes|integer|between:1,5',
            'prior_authorization_required' => 'boolean',
            'quantity_limit'               => 'boolean',
            'quantity_limit_text'          => 'nullable|string|max:200',
            'step_therapy_required'        => 'boolean',
            'notes'                        => 'nullable|string|max:2000',
            'is_active'                    => 'boolean',
        ]);
        $entry->update($validated);
        return response()->json(['entry' => $entry->fresh()]);
    }

    /**
     * Check whether a drug is on formulary.
     * GET /formulary/check?drug_name=xxx OR ?rxnorm_code=yyy
     */
    public function check(Request $request): JsonResponse
    {
        $this->gate(['pharmacy', 'primary_care', 'therapies', 'qa_compliance', 'it_admin', 'finance']);
        $u = Auth::user();
        $drug  = trim((string) $request->query('drug_name', ''));
        $code  = trim((string) $request->query('rxnorm_code', ''));
        abort_unless($drug !== '' || $code !== '', 422, 'Provide drug_name or rxnorm_code.');

        $q = FormularyEntry::forTenant($u->tenant_id)->active();
        if ($code !== '') {
            $q->where('rxnorm_code', $code);
        } else {
            $q->where(function ($w) use ($drug) {
                $w->where('drug_name', 'ilike', $drug)
                  ->orWhere('generic_name', 'ilike', $drug);
            });
        }
        $match = $q->first();
        return response()->json([
            'on_formulary' => (bool) $match,
            'entry'        => $match,
            'restrictions' => $match ? array_filter([
                $match->prior_authorization_required ? 'prior_authorization' : null,
                $match->quantity_limit ? 'quantity_limit' : null,
                $match->step_therapy_required ? 'step_therapy' : null,
            ]) : [],
        ]);
    }

    public function storeDetermination(Request $request, Participant $participant): JsonResponse
    {
        $this->gate(['pharmacy', 'primary_care', 'qa_compliance', 'it_admin']);
        $u = Auth::user();
        abort_unless($participant->tenant_id === $u->tenant_id, 404);

        $validated = $request->validate([
            'drug_name'              => 'required|string|max:200',
            'rxnorm_code'            => 'nullable|string|max:20',
            'determination_type'     => 'required|in:' . implode(',', CoverageDetermination::TYPES),
            'clinical_justification' => 'nullable|string|max:4000',
            // Phase A1: tenant-scoped FK validation. Prevents a user in tenant A
            // from creating a coverage determination that references a
            // formulary entry from tenant B (would have been silently accepted).
            'formulary_entry_id'     => [
                'nullable',
                'integer',
                \Illuminate\Validation\Rule::exists('emr_formulary_entries', 'id')
                    ->where('tenant_id', $u->tenant_id),
            ],
        ]);

        $row = CoverageDetermination::create(array_merge($validated, [
            'tenant_id'            => $u->tenant_id,
            'participant_id'       => $participant->id,
            'status'               => 'pending',
            'requested_at'         => now()->toDateString(),
            'requested_by_user_id' => $u->id,
        ]));
        AuditLog::record(
            action: 'formulary.coverage_determination_requested',
            tenantId: $u->tenant_id, userId: $u->id,
            resourceType: 'coverage_determination', resourceId: $row->id,
            description: "Coverage determination requested: {$row->drug_name} ({$row->determination_type})",
        );
        return response()->json(['determination' => $row], 201);
    }
}
