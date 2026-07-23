<?php

namespace App\Services\Ai\Company\Ventures\Assessment;

use App\Models\AiVenture;
use App\Models\AiVentureAssessmentRun;
use App\Models\AiVentureComprehensionRun;
use App\Models\AiVentureQuestionAnswer;
use App\Services\Ai\Strategy\StrategyCanonicalHash;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Orchestrates a full venture assessment: ask every catalog question, answer
 * each from the most honest data available, decide the #1 focus + decision
 * queue, and report the data readiness (how much Atlas can answer today vs.
 * what external sources it still needs to become autonomous).
 *
 * This is the decision brain that sits on top of comprehension: comprehension
 * tells Atlas what the company IS; assessment tells Atlas what to DO and what
 * it still needs to know.
 */
class VentureAssessmentService
{
    public function __construct(
        private readonly VentureQuestionEngine $engine,
        private readonly VentureFocusDecider $decider,
    ) {}

    /**
     * @param  array<string,mixed>  $opts
     * @return array<string,mixed>
     */
    public function run(AiVenture $venture, array $opts = []): array
    {
        $comprehension = $this->latestComprehension($venture);

        $run = AiVentureAssessmentRun::query()->create([
            'uuid' => (string) Str::uuid(),
            'venture_id' => $venture->id,
            'comprehension_run_id' => $comprehension?->id,
            'stage' => $venture->stage,
            'status' => 'running',
            'started_at' => Carbon::now(),
            'run_hash' => StrategyCanonicalHash::sha256([
                'venture_id' => $venture->id,
                'stage' => $venture->stage,
                'token' => (string) Str::uuid(),
            ]),
        ]);

        // 1. Answer every non-focus question from honest data.
        $answers = $this->engine->answer($venture, $run, $comprehension);

        // 2. Decide the focus + decision queue + data gaps.
        $decision = $this->decider->decide($run, $venture, $answers);

        // 3. Record the focus-dimension answers from the decision.
        $focusAnswers = [];
        foreach ($decision['focus_answers'] as $fa) {
            $focusAnswers[] = $this->engine->persistFocusAnswer($run, $venture, $fa['question'], $fa['answer']);
        }

        $allAnswers = array_merge($answers, $focusAnswers);
        $counts = $this->counts($allAnswers);
        $total = count($allAnswers);
        $readiness = $total > 0
            ? round((($counts['answered'] + 0.5 * $counts['partial']) / $total) * 100, 2)
            : 0.0;

        $summary = [
            'by_status' => $counts,
            'by_dimension' => $this->byDimension($allAnswers),
            'comprehension_run' => $comprehension?->uuid,
            'top_focus' => $decision['focus']['headline'],
        ];

        $run->forceFill([
            'status' => 'completed',
            'questions_total' => $total,
            'answered_count' => $counts['answered'],
            'partial_count' => $counts['partial'],
            'blocked_internal_count' => $counts['blocked_internal'],
            'blocked_external_count' => $counts['blocked_external'],
            'data_readiness_pct' => $readiness,
            'focus' => $decision['focus'],
            'data_gaps' => $decision['data_gaps'],
            'summary' => $summary,
            'completed_at' => Carbon::now(),
        ])->save();

        return [
            'schema_version' => 'atlas.ai.venture.assessment_report.v1',
            'run_uuid' => $run->uuid,
            'venture_id' => $venture->venture_id,
            'stage' => $run->stage,
            'comprehension_linked' => $comprehension !== null,
            'questions_total' => $total,
            'data_readiness_pct' => $readiness,
            'by_status' => $counts,
            'focus' => $decision['focus'],
            'data_gaps' => $decision['data_gaps'],
        ];
    }

    /**
     * Pick the comprehension snapshot the assessment reads. A partial run
     * (e.g. `comprehend --only=business_rule`) would blind the risk/health
     * dimensions and let an existential leak go unscored — so prefer the most
     * recent COMPLETE run that covered the `problem` capability; only fall back
     * to the latest completed run when no full snapshot exists.
     */
    private function latestComprehension(AiVenture $venture): ?AiVentureComprehensionRun
    {
        $completed = AiVentureComprehensionRun::query()
            ->where('venture_id', $venture->id)
            ->where('status', 'completed')
            ->orderByDesc('created_at')
            ->get();

        foreach ($completed as $run) {
            $caps = is_array($run->capabilities_run) ? $run->capabilities_run : [];
            if (in_array('problem', $caps, true)) {
                return $run;
            }
        }

        return $completed->first();
    }

    /**
     * @param  array<int,AiVentureQuestionAnswer>  $answers
     * @return array<string,int>
     */
    private function counts(array $answers): array
    {
        $counts = ['answered' => 0, 'partial' => 0, 'blocked_internal' => 0, 'blocked_external' => 0];
        foreach ($answers as $a) {
            if (array_key_exists($a->status, $counts)) {
                $counts[$a->status]++;
            }
        }

        return $counts;
    }

    /**
     * @param  array<int,AiVentureQuestionAnswer>  $answers
     * @return array<string,int>
     */
    private function byDimension(array $answers): array
    {
        $out = [];
        foreach ($answers as $a) {
            $out[$a->dimension] = ($out[$a->dimension] ?? 0) + 1;
        }

        return $out;
    }
}
