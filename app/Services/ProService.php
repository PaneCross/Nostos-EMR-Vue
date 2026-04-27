<?php

// ─── ProService ──────────────────────────────────────────────────────────────
// Phase G7. Send due surveys via SMS gateway + receive responses.
// Emits critical alert on concerning trends (pain score >=8 twice in a row,
// or 3-point drop in function score week-over-week).
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Participant;
use App\Models\ProResponse;
use App\Models\ProSurvey;
use App\Models\ProSurveySchedule;
use App\Services\Sms\SmsGateway;

class ProService
{
    public function __construct(private SmsGateway $sms, private AlertService $alerts) {}

    /** Send due surveys that are scheduled at/before now. */
    public function sendDue(): int
    {
        $sent = 0;
        $due = ProSurveySchedule::where('is_active', true)
            ->where('next_send_at', '<=', now())->get();

        foreach ($due as $row) {
            $p = Participant::find($row->participant_id);
            $survey = ProSurvey::find($row->survey_id);
            if (! $p || ! $survey) continue;

            $body = "[NostosEMR] {$survey->title} : reply via portal. Weekly check-in.";
            $to = $p->mobile_phone ?? $p->home_phone ?? null;
            $result = $to ? $this->sms->send($to, $body) : ['sent' => false, 'channel' => 'null'];

            $row->update([
                'last_sent_at' => now(),
                'next_send_at' => $this->nextSendAt($survey->cadence),
            ]);
            if ($result['sent']) $sent++;
        }
        return $sent;
    }

    public function recordResponse(
        Participant $p,
        ProSurvey $survey,
        array $answers,
        string $channel = 'portal',
    ): ProResponse {
        $agg = $this->aggregate($answers);
        $r = ProResponse::create([
            'tenant_id'        => $p->tenant_id,
            'participant_id'   => $p->id,
            'survey_id'        => $survey->id,
            'answers'          => $answers,
            'aggregate_score'  => $agg,
            'received_at'      => now(),
            'delivery_channel' => $channel,
        ]);

        AuditLog::record(
            action: 'pro.response_recorded',
            tenantId: $p->tenant_id,
            userId: null,
            resourceType: 'pro_response',
            resourceId: $r->id,
            description: "PRO response recorded via {$channel} (aggregate={$agg}).",
        );

        $this->checkConcerningTrend($p, $survey);
        return $r;
    }

    private function nextSendAt(string $cadence): \Carbon\Carbon
    {
        return match ($cadence) {
            'biweekly' => now()->addDays(14),
            'monthly'  => now()->addMonth(),
            default    => now()->addWeek(),
        };
    }

    private function aggregate(array $answers): int
    {
        return (int) array_sum(array_map(fn ($v) => is_numeric($v) ? (int) $v : 0, $answers));
    }

    /**
     * Concerning trend detection:
     *   - 2 consecutive responses with aggregate >= 8 (for pain) → critical
     *   - 3+ point drop aggregate week-over-week → warning
     */
    private function checkConcerningTrend(Participant $p, ProSurvey $survey): void
    {
        $recent = ProResponse::forTenant($p->tenant_id)
            ->where('participant_id', $p->id)
            ->where('survey_id', $survey->id)
            ->orderByDesc('received_at')->limit(2)->get();

        if ($recent->count() >= 2) {
            $scores = $recent->pluck('aggregate_score')->all();
            if (str_contains($survey->key, 'pain') && $scores[0] >= 8 && $scores[1] >= 8) {
                $this->alerts->create([
                    'tenant_id'          => $p->tenant_id,
                    'participant_id'     => $p->id,
                    'source_module'      => 'pro',
                    'alert_type'         => 'pro_pain_persistent',
                    'severity'           => 'critical',
                    'title'              => 'Persistent severe pain reported',
                    'message'            => "Participant reported pain >=8/10 on two consecutive surveys (latest {$scores[0]}, prior {$scores[1]}).",
                    'target_departments' => ['primary_care', 'behavioral_health'],
                    'metadata'           => ['survey_key' => $survey->key],
                ]);
            } elseif (($scores[1] - $scores[0]) >= 3) {
                $this->alerts->create([
                    'tenant_id'          => $p->tenant_id,
                    'participant_id'     => $p->id,
                    'source_module'      => 'pro',
                    'alert_type'         => 'pro_drop_detected',
                    'severity'           => 'warning',
                    'title'              => 'PRO week-over-week drop',
                    'message'            => "Participant PRO aggregate dropped from {$scores[1]} to {$scores[0]}.",
                    'target_departments' => ['primary_care'],
                    'metadata'           => ['survey_key' => $survey->key],
                ]);
            }
        }
    }
}
