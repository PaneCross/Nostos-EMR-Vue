<?php

namespace App\Jobs;

use App\Models\Participant;
use App\Services\AlertService;
use App\Services\PredictiveRiskService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class PredictiveRiskScoringJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function handle(PredictiveRiskService $svc, AlertService $alerts): void
    {
        $participants = Participant::where('enrollment_status', 'enrolled')->get();

        foreach ($participants as $p) {
            $scores = $svc->score($p);
            foreach ($scores as $score) {
                if ($score->band === 'high') {
                    $alerts->create([
                        'tenant_id'          => $p->tenant_id,
                        'participant_id'     => $p->id,
                        'source_module'      => 'predictive',
                        'alert_type'         => "predictive_high_{$score->risk_type}",
                        'severity'           => 'warning',
                        'title'              => "High predictive {$score->risk_type} risk",
                        'message'            => "Participant #{$p->id} {$score->risk_type} risk score {$score->score}/100 (high band).",
                        'target_departments' => ['primary_care', 'social_work'],
                        'metadata'           => ['risk_type' => $score->risk_type, 'score' => $score->score, 'model_version' => $score->model_version],
                    ]);
                }
            }
        }
    }
}
