<?php

// ─── AssessmentController ─────────────────────────────────────────────────────
// Manages clinical assessments for a participant (PHQ-9, MMSE, fall risk, etc.).
// The /due endpoint returns overdue + due-within-14-days assessments for dashboard alerts.
// ──────────────────────────────────────────────────────────────────────────────

namespace App\Http\Controllers;

use App\Http\Requests\StoreAssessmentRequest;
use App\Models\Assessment;
use App\Models\AuditLog;
use App\Models\Participant;
use App\Models\StaffTask;
use App\Services\AlertService;
use App\Services\AssessmentScoringService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AssessmentController extends Controller
{
    public function __construct(
        private AlertService $alertService,
        private AssessmentScoringService $scorer,
    ) {}

    private function authorizeForTenant(Participant $participant, $user): void
    {
        abort_if($participant->tenant_id !== $user->tenant_id, 403);
    }

    /**
     * GET /participants/{participant}/assessments
     * Returns all assessments, newest first.
     */
    public function index(Request $request, Participant $participant): JsonResponse
    {
        $user = $request->user();
        $this->authorizeForTenant($participant, $user);

        $assessments = $participant->assessments()
            ->with('author:id,first_name,last_name,department')
            ->orderByDesc('completed_at')
            ->get();

        return response()->json($assessments);
    }

    /**
     * POST /participants/{participant}/assessments
     * Records a completed assessment.
     */
    public function store(StoreAssessmentRequest $request, Participant $participant): JsonResponse
    {
        $user = $request->user();
        $this->authorizeForTenant($participant, $user);

        $assessment = Assessment::create(array_merge($request->validated(), [
            'participant_id'      => $participant->id,
            'tenant_id'           => $user->tenant_id,
            'authored_by_user_id' => $user->id,
            'department'          => $user->department,
            'responses'           => $request->input('responses', []),
        ]));

        AuditLog::record(
            action: 'participant.assessment.created',
            tenantId: $user->tenant_id,
            userId: $user->id,
            resourceType: 'participant',
            resourceId: $participant->id,
            description: "{$assessment->typeLabel()} assessment completed for {$participant->mrn}"
                . ($assessment->score !== null ? " (score: {$assessment->score})" : ''),
            newValues: ['assessment_id' => $assessment->id, 'type' => $assessment->assessment_type, 'score' => $assessment->score],
        );

        // W4-4: Create clinical alert when a scored assessment crosses a threshold.
        $this->maybeCreateAssessmentAlert($assessment, $participant, $user->tenant_id);

        // Phase R3 : Auto-create a StaffTask when AssessmentScoringService
        // has a referral suggestion for this (instrument, band) combination.
        $this->maybeAutoReferral($assessment, $participant, $user);

        // Phase W1 : optional Nursing Director routing per Org Settings preference.
        // Morse fall scale >= 45 (High Risk band) → ping nursing_director if the
        // org (or this site) has the preference enabled. Default OFF.
        $this->maybeNotifyNursingDirectorOnFallRisk($assessment, $participant, $user);

        return response()->json($assessment->load('author:id,first_name,last_name'), 201);
    }

    /**
     * Phase W1: nursing_director.fall_risk_threshold preference handling.
     * The hardwired primary_care/idt alert (above) fires regardless; this is
     * an additional routing layer the org opts into via /executive/org-settings.
     * Cascade: site_id (from participant) → org default → catalog default OFF.
     */
    private function maybeNotifyNursingDirectorOnFallRisk(Assessment $assessment, Participant $participant, $user): void
    {
        if ($assessment->assessment_type !== 'fall_risk_morse') return;
        if (($assessment->score ?? 0) < 45) return;  // High Risk band threshold

        $prefs = app(\App\Services\NotificationPreferenceService::class);
        $key = 'designation.nursing_director.fall_risk_threshold';
        if (! $prefs->shouldNotify($user->tenant_id, $key, $participant->site_id)) return;

        $director = \App\Models\User::where('tenant_id', $user->tenant_id)
            ->withDesignation('nursing_director')
            ->where('is_active', true)
            ->first();
        if (! $director) return;

        $name = $participant->first_name . ' ' . $participant->last_name;
        $this->alertService->create([
            'tenant_id'          => $user->tenant_id,
            'participant_id'     => $participant->id,
            'alert_type'         => 'nursing_director_fall_risk',
            'title'              => "Fall risk High : Nursing Director review",
            'message'            => "Morse Fall Scale {$assessment->score} for {$name}: High Risk band. Forwarded for nursing oversight.",
            'severity'           => 'warning',
            'source_module'      => 'assessments',
            'target_departments' => ['home_care'],
            'created_by_system'  => true,
            'metadata'           => [
                'assessment_id'        => $assessment->id,
                'morse_score'          => $assessment->score,
                'nursing_director_id'  => $director->id,
            ],
        ]);
    }

    private function maybeAutoReferral(Assessment $assessment, Participant $participant, $user): void
    {
        if (! in_array($assessment->assessment_type, AssessmentScoringService::INSTRUMENTS, true)) {
            return;
        }
        $responses = is_array($assessment->responses) ? $assessment->responses : [];
        if (empty($responses)) return;
        $scored = $this->scorer->score($assessment->assessment_type, $responses);
        if (! $scored || empty($scored['band'])) return;

        $hint = $this->scorer->referralFor($assessment->assessment_type, $scored['band']);
        if (! $hint) return;

        StaffTask::create([
            'tenant_id'              => $user->tenant_id,
            'participant_id'         => $participant->id,
            'assigned_to_department' => $hint['dept'],
            'created_by_user_id'     => $user->id,
            'title'                  => "Assessment referral: {$assessment->typeLabel()}",
            'description'            => $hint['goal'] . " (Auto-created from assessment #{$assessment->id}; band={$scored['band']}.)",
            'priority'               => in_array($scored['band'], ['severe', 'substantial'], true) ? 'high' : 'normal',
            'status'                 => 'pending',
            'related_to_type'        => Assessment::class,
            'related_to_id'          => $assessment->id,
        ]);
    }

    /**
     * Create a clinical alert when an assessment crosses a threshold.
     *
     * Standard types (Braden/MoCA/OHAT): single operator/value threshold → warning.
     *
     * Special cases:
     *   fall_history     : response-based: alert when responses.falls_12_months >= 2 (warning).
     *                      No numeric score is required.
     *   lace_plus_index  : dual threshold: score >= 10 = critical, 5-9 = warning, <5 = no alert.
     */
    private function maybeCreateAssessmentAlert(Assessment $assessment, Participant $participant, int $tenantId): void
    {
        $label      = $assessment->typeLabel();
        $name       = $participant->first_name . ' ' . $participant->last_name;
        $alertBase  = [
            'tenant_id'         => $tenantId,
            'participant_id'    => $participant->id,
            'alert_type'        => "assessment_{$assessment->assessment_type}_threshold",
            'target_departments'=> ['primary_care', 'idt'],
            'source_module'     => 'assessments',
            'metadata'          => ['assessment_id' => $assessment->id],
            'created_by_system' => true,
        ];

        // ── fall_history: response-based alert (no numeric score) ─────────────
        if ($assessment->assessment_type === 'fall_history') {
            $falls = (int) ($assessment->responses['falls_12_months'] ?? 0);
            if ($falls >= 2) {
                $this->alertService->create(array_merge($alertBase, [
                    'severity' => 'warning',
                    'title'    => "Fall History Alert",
                    'message'  => "{$name} reported {$falls} falls in the past 12 months. Fall prevention review recommended.",
                    'metadata' => array_merge($alertBase['metadata'], ['falls_12_months' => $falls]),
                ]));
            }
            return;
        }

        // ── lace_plus_index: dual threshold ───────────────────────────────────
        if ($assessment->assessment_type === 'lace_plus_index') {
            if ($assessment->score === null) {
                return;
            }
            if ($assessment->score >= 10) {
                $this->alertService->create(array_merge($alertBase, [
                    'severity' => 'critical',
                    'title'    => "LACE+ Index - High Readmission Risk",
                    'message'  => "LACE+ score {$assessment->score}/19 for {$name}: High readmission risk. Intensive care coordination required.",
                    'metadata' => array_merge($alertBase['metadata'], ['score' => $assessment->score]),
                ]));
            } elseif ($assessment->score >= 5) {
                $this->alertService->create(array_merge($alertBase, [
                    'severity' => 'warning',
                    'title'    => "LACE+ Index - Moderate Readmission Risk",
                    'message'  => "LACE+ score {$assessment->score}/19 for {$name}: Moderate readmission risk. Care plan review recommended.",
                    'metadata' => array_merge($alertBase['metadata'], ['score' => $assessment->score]),
                ]));
            }
            return;
        }

        // ── Standard single-threshold types (Braden, MoCA, OHAT) ─────────────
        $threshold = Assessment::ALERT_THRESHOLD[$assessment->assessment_type] ?? null;
        if ($threshold === null || $assessment->score === null) {
            return;
        }

        $triggered = match ($threshold['operator']) {
            '<='    => $assessment->score <= $threshold['value'],
            '<'     => $assessment->score <  $threshold['value'],
            '>='    => $assessment->score >= $threshold['value'],
            '>'     => $assessment->score >  $threshold['value'],
            default => false,
        };

        if (! $triggered) {
            return;
        }

        $this->alertService->create(array_merge($alertBase, [
            'severity' => 'warning',
            'title'    => "{$label} Alert",
            'message'  => "{$label} score {$assessment->score} for {$name}: {$assessment->scoredLabel()}",
            'metadata' => array_merge($alertBase['metadata'], ['score' => $assessment->score]),
        ]));
    }

    /**
     * GET /participants/{participant}/assessments/due
     * Returns overdue and due-within-14-days assessments, ordered by next_due_date ASC.
     * Used by dashboard alerts and the Assessments tab status badges.
     */
    public function due(Request $request, Participant $participant): JsonResponse
    {
        $user = $request->user();
        $this->authorizeForTenant($participant, $user);

        $overdue = $participant->assessments()
            ->overdue()
            ->with('author:id,first_name,last_name')
            ->orderBy('next_due_date')
            ->get()
            ->each(fn ($a) => $a->status_label = 'overdue');

        $dueSoon = $participant->assessments()
            ->dueSoon(14)
            ->with('author:id,first_name,last_name')
            ->orderBy('next_due_date')
            ->get()
            ->each(fn ($a) => $a->status_label = 'due_soon');

        return response()->json([
            'overdue'  => $overdue,
            'due_soon' => $dueSoon,
        ]);
    }
}
