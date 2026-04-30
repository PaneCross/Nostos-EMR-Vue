<?php

// ─── CommitteeController ─────────────────────────────────────────────────────
// Phase 15.8 : CRUD for committees, members, meetings, and votes.
// Gated to qa_compliance + executive + it_admin + super_admin.
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Committee;
use App\Models\CommitteeMeeting;
use App\Models\CommitteeMember;
use App\Models\CommitteeVote;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class CommitteeController extends Controller
{
    private function gate(): void
    {
        $u = Auth::user();
        abort_if(!$u, 401);
        abort_unless(
            $u->isSuperAdmin() || in_array($u->department, ['qa_compliance', 'executive', 'it_admin']),
            403
        );
    }

    public function index(Request $request)
    {
        $this->gate();
        $u = Auth::user();
        $committees = Committee::forTenant($u->effectiveTenantId())
            ->withCount(['members', 'meetings'])
            ->with(['meetings' => fn ($q) => $q->orderByDesc('scheduled_date')->limit(3)])
            ->orderBy('name')->get();

        if ($request->wantsJson()) {
            return response()->json(['committees' => $committees]);
        }
        return \Inertia\Inertia::render('Committees/Index', [
            'committees' => $committees,
            'types'      => Committee::TYPES,
            'roles'      => CommitteeMember::ROLES,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->gate();
        $u = Auth::user();
        $validated = $request->validate([
            'name'            => 'required|string|max:150',
            'committee_type'  => 'required|in:' . implode(',', Committee::TYPES),
            'charter'         => 'nullable|string|max:4000',
            'meeting_cadence' => 'nullable|string|max:40',
        ]);
        $committee = Committee::create(array_merge($validated, [
            'tenant_id' => $u->effectiveTenantId(),
            'is_active' => true,
        ]));
        AuditLog::record(
            action: 'committee.created',
            tenantId: $u->tenant_id, userId: $u->id,
            resourceType: 'committee', resourceId: $committee->id,
            description: "Committee created: {$committee->name}",
        );
        return response()->json(['committee' => $committee], 201);
    }

    public function addMember(Request $request, Committee $committee): JsonResponse
    {
        $this->gate();
        $u = Auth::user();
        abort_unless($committee->tenant_id === $u->effectiveTenantId(), 404);
        $validated = $request->validate([
            'user_id'       => 'nullable|integer',
            'external_name' => 'nullable|string|max:150',
            'role'          => 'required|in:' . implode(',', CommitteeMember::ROLES),
            'term_start'    => 'nullable|date',
            'term_end'      => 'nullable|date',
            'voting_member' => 'boolean',
        ]);
        abort_unless(
            ! empty($validated['user_id']) || ! empty($validated['external_name']),
            422, 'Either user_id or external_name must be provided.'
        );
        $m = $committee->members()->create($validated);
        return response()->json(['member' => $m], 201);
    }

    public function scheduleMeeting(Request $request, Committee $committee): JsonResponse
    {
        $this->gate();
        $u = Auth::user();
        abort_unless($committee->tenant_id === $u->effectiveTenantId(), 404);
        $validated = $request->validate([
            'scheduled_date' => 'required|date',
            'location'       => 'nullable|string|max:150',
            'agenda'         => 'nullable|string|max:8000',
        ]);
        $meeting = $committee->meetings()->create(array_merge($validated, [
            'status' => 'scheduled',
        ]));
        AuditLog::record(
            action: 'committee.meeting_scheduled',
            tenantId: $u->tenant_id, userId: $u->id,
            resourceType: 'committee_meeting', resourceId: $meeting->id,
            description: "Meeting scheduled for {$committee->name} on {$meeting->scheduled_date->toDateString()}",
        );
        return response()->json(['meeting' => $meeting], 201);
    }

    public function recordMeeting(Request $request, CommitteeMeeting $meeting): JsonResponse
    {
        $this->gate();
        $u = Auth::user();
        abort_unless($meeting->committee->tenant_id === $u->effectiveTenantId(), 404);
        $validated = $request->validate([
            'minutes'         => 'nullable|string|max:20000',
            'attendees_json'  => 'nullable|array',
            'status'          => 'sometimes|in:' . implode(',', CommitteeMeeting::STATUSES),
        ]);
        $meeting->update(array_merge($validated, [
            'held_at' => ($validated['status'] ?? null) === 'held' ? now() : $meeting->held_at,
        ]));
        return response()->json(['meeting' => $meeting->fresh()]);
    }

    public function recordVote(Request $request, CommitteeMeeting $meeting): JsonResponse
    {
        $this->gate();
        $u = Auth::user();
        abort_unless($meeting->committee->tenant_id === $u->effectiveTenantId(), 404);
        $validated = $request->validate([
            'motion_text'   => 'required|string|max:500',
            'votes_yes'     => 'required|integer|min:0',
            'votes_no'      => 'required|integer|min:0',
            'votes_abstain' => 'nullable|integer|min:0',
            'outcome'       => 'required|in:' . implode(',', CommitteeVote::OUTCOMES),
            'notes'         => 'nullable|string|max:2000',
        ]);
        $vote = $meeting->votes()->create($validated);
        AuditLog::record(
            action: 'committee.vote_recorded',
            tenantId: $u->tenant_id, userId: $u->id,
            resourceType: 'committee_vote', resourceId: $vote->id,
            description: "Motion '{$vote->motion_text}' → {$vote->outcome} ({$vote->votes_yes}-{$vote->votes_no}-{$vote->votes_abstain})",
        );
        return response()->json(['vote' => $vote], 201);
    }
}
