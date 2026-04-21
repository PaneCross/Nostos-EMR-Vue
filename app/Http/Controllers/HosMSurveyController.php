<?php

// ─── HosMSurveyController ─────────────────────────────────────────────────────
// REST API for Health Outcomes Survey for Medicare (HOS-M) annual surveys.
//
// Route list:
//   GET  /billing/hos-m               → index()   — list surveys with filters
//   POST /billing/hos-m               → store()   — create survey record
//   PUT  /billing/hos-m/{survey}      → update()  — update survey responses
//   POST /billing/hos-m/{survey}/submit → submit() — mark as submitted to CMS
//
// Department access: finance + primary_care (administrators of the survey)
//   + super_admin + it_admin.
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\HosMSurvey;
use App\Models\Participant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class HosMSurveyController extends Controller
{
    // ── Helpers ───────────────────────────────────────────────────────────────

    private function authorizeAccess(Request $request): void
    {
        $user = $request->user();
        abort_if(
            !$user->isSuperAdmin()
            && !in_array($user->department, ['finance', 'primary_care', 'it_admin']),
            403
        );
    }

    // ── Survey Index ──────────────────────────────────────────────────────────

    /**
     * List HOS-M surveys for the tenant.
     * Filters: ?participant_id= ?survey_year= ?completed= ?submitted_to_cms=
     *
     * GET /billing/hos-m
     */
    public function index(Request $request): InertiaResponse
    {
        $this->authorizeAccess($request);
        $tenantId    = $request->user()->tenant_id;
        $currentYear = now()->year;

        // Year selector: defaults to current year, clamps to valid range.
        $selectedYear = (int) ($request->query('year') ?? $currentYear);
        // Allow viewing up to one year ahead of the current year (for planning);
        // lower bound of 2020 covers all realistic historical PACE data.
        if ($selectedYear < 2020 || $selectedYear > $currentYear + 1) {
            $selectedYear = $currentYear;
        }

        // Distinct years we have survey data for (plus the current year), sorted desc.
        $dataYears = HosMSurvey::forTenant($tenantId)
            ->select('survey_year')
            ->distinct()
            ->pluck('survey_year')
            ->toArray();
        $availableYears = collect(array_merge($dataYears, [$currentYear, $selectedYear]))
            ->unique()
            ->sortDesc()
            ->values()
            ->all();

        // Full list of surveys for the selected year — drives the table.
        $surveys = HosMSurvey::forTenant($tenantId)
            ->forYear($selectedYear)
            ->with([
                'participant:id,mrn,first_name,last_name',
                'administeredBy:id,first_name,last_name',
            ])
            ->orderBy('administered_at', 'desc')
            ->get();

        $enrolled = Participant::where('tenant_id', $tenantId)
            ->where('enrollment_status', 'enrolled')
            ->count();

        $stats = [
            'total_enrolled'   => $enrolled,
            'surveyed'         => HosMSurvey::forTenant($tenantId)->forYear($selectedYear)->count(),
            'completed'        => HosMSurvey::forTenant($tenantId)->forYear($selectedYear)->where('completed', true)->count(),
            'submitted_to_cms' => HosMSurvey::forTenant($tenantId)->forYear($selectedYear)->where('submitted_to_cms', true)->count(),
        ];

        // Enrolled participants for the "Add Survey" picker.
        // HOS-M is a post-enrollment annual survey per CMS HPMS requirements —
        // only enrolled participants (not potential enrollees) are eligible.
        $enrolledParticipants = Participant::where('tenant_id', $tenantId)
            ->where('enrollment_status', 'enrolled')
            ->where('is_active', true)
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->get(['id', 'mrn', 'first_name', 'last_name']);

        return Inertia::render('Finance/HosMSurvey', [
            'surveys'              => $surveys,
            'stats'                => $stats,
            'selectedYear'         => $selectedYear,
            'currentYear'          => $currentYear,
            'availableYears'       => $availableYears,
            'enrolledParticipants' => $enrolledParticipants,
        ]);
    }

    // ── Survey Store ──────────────────────────────────────────────────────────

    /**
     * Create an HOS-M survey record for a participant.
     * Enforces one survey per participant per year (DB unique constraint).
     *
     * POST /billing/hos-m
     */
    public function store(Request $request): JsonResponse
    {
        $this->authorizeAccess($request);
        $tenantId = $request->user()->tenant_id;

        $data = $request->validate([
            'participant_id'   => ['required', 'integer', 'exists:emr_participants,id'],
            'survey_year'      => ['required', 'integer', 'min:2020', 'max:' . (now()->year + 1)],
            'administered_at'  => ['required', 'date'],
            'completed'        => ['boolean'],
            'responses'        => ['nullable', 'array'],
            'responses.physical_health'  => ['nullable', 'integer', 'min:1', 'max:5'],
            'responses.mental_health'    => ['nullable', 'integer', 'min:1', 'max:5'],
            'responses.pain'             => ['nullable', 'integer', 'min:1', 'max:5'],
            'responses.falls_past_year'  => ['nullable', 'in:0,1'],
            'responses.fall_injuries'    => ['nullable', 'in:0,1'],
        ]);

        abort_if(
            !Participant::where('id', $data['participant_id'])
                ->where('tenant_id', $tenantId)
                ->exists(),
            403
        );

        try {
            $survey = HosMSurvey::create(array_merge($data, [
                'tenant_id'               => $tenantId,
                'administered_by_user_id' => $request->user()->id,
            ]));
        } catch (\Illuminate\Database\QueryException $e) {
            // Unique constraint violation — survey already exists for this year
            return response()->json([
                'error' => "A HOS-M survey already exists for this participant in {$data['survey_year']}.",
            ], 409);
        }

        AuditLog::record(
            action: 'billing.hosm.create',
            resourceType: 'HosMSurvey',
            resourceId: $survey->id,
            tenantId: $tenantId,
            userId: $request->user()->id,
            newValues: $data
        );

        return response()->json($survey->load([
            'participant:id,mrn,first_name,last_name',
            'administeredBy:id,first_name,last_name',
        ]), 201);
    }

    // ── Survey Update ─────────────────────────────────────────────────────────

    /**
     * Update survey responses or completion status.
     * Cannot update a survey that has already been submitted to CMS.
     *
     * PUT /billing/hos-m/{survey}
     */
    public function update(Request $request, HosMSurvey $survey): JsonResponse
    {
        $this->authorizeAccess($request);
        abort_if($survey->tenant_id !== $request->user()->tenant_id, 403);
        abort_if($survey->submitted_to_cms, 409, 'Cannot update a survey that has been submitted to CMS.');

        $old = $survey->only(['completed', 'responses']);

        $data = $request->validate([
            'completed'                  => ['boolean'],
            'responses'                  => ['nullable', 'array'],
            'responses.physical_health'  => ['nullable', 'integer', 'min:1', 'max:5'],
            'responses.mental_health'    => ['nullable', 'integer', 'min:1', 'max:5'],
            'responses.pain'             => ['nullable', 'integer', 'min:1', 'max:5'],
            'responses.falls_past_year'  => ['nullable', 'in:0,1'],
            'responses.fall_injuries'    => ['nullable', 'in:0,1'],
        ]);

        $survey->update($data);

        AuditLog::record(
            action: 'billing.hosm.update',
            resourceType: 'HosMSurvey',
            resourceId: $survey->id,
            tenantId: $request->user()->tenant_id,
            userId: $request->user()->id,
            oldValues: $old,
            newValues: $data
        );

        return response()->json($survey->fresh()->load([
            'participant:id,mrn,first_name,last_name',
            'administeredBy:id,first_name,last_name',
        ]));
    }

    // ── Submit to CMS ─────────────────────────────────────────────────────────

    /**
     * Mark a completed survey as submitted to CMS.
     * Requires the survey to be completed first.
     *
     * POST /billing/hos-m/{survey}/submit
     */
    public function submit(Request $request, HosMSurvey $survey): JsonResponse
    {
        $this->authorizeAccess($request);
        abort_if($survey->tenant_id !== $request->user()->tenant_id, 403);
        abort_if(!$survey->completed, 422, 'Survey must be completed before submitting to CMS.');
        abort_if($survey->submitted_to_cms, 409, 'Survey has already been submitted to CMS.');

        $survey->update([
            'submitted_to_cms' => true,
            'submitted_at'     => now(),
        ]);

        AuditLog::record(
            action: 'billing.hosm.submit_to_cms',
            resourceType: 'HosMSurvey',
            resourceId: $survey->id,
            tenantId: $request->user()->tenant_id,
            userId: $request->user()->id,
            newValues: ['submitted_to_cms' => true]
        );

        return response()->json($survey->fresh()->load([
            'participant:id,mrn,first_name,last_name',
        ]));
    }
}
