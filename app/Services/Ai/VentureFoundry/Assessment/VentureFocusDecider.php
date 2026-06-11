<?php

namespace App\Services\Ai\VentureFoundry\Assessment;

use App\Models\AiVenture;
use App\Models\AiVentureAssessmentRun;
use App\Models\AiVentureQuestionAnswer;

/**
 * Turns the answered questions into a DECISION: what is the single most
 * important thing for this company right now, a ranked decision queue, and the
 * external data gaps that block full autonomy.
 *
 * Deterministic and explainable: priority = severity × dimension-at-stage
 * weight × actionability. Existential risk and a broken critical path always
 * outrank growth; at S0/S1 validation outranks scaling; at S2+ growth and
 * retention rise. No black box — every score is reconstructable.
 */
class VentureFocusDecider
{
    private const SEVERITY_WEIGHT = [
        'critical' => 4.0,
        'high' => 3.0,
        'medium' => 2.0,
        'low' => 1.0,
    ];

    /**
     * @param  array<int,AiVentureQuestionAnswer>  $answers
     * @return array<string,mixed>
     */
    public function decide(AiVentureAssessmentRun $run, AiVenture $venture, array $answers): array
    {
        $stage = (string) $run->stage;
        $scored = [];

        foreach ($answers as $answer) {
            $score = $this->score($answer, $stage);
            $answer->priority_score = $score;
            $answer->save();
            $scored[] = ['answer' => $answer, 'score' => $score];
        }

        usort($scored, static fn ($a, $b): int => $b['score'] <=> $a['score']);

        $top = $scored[0]['answer'] ?? null;
        $focusStatement = $top !== null
            ? sprintf('%s — %s', VentureQuestionCatalog::DIMENSION_LABELS[$top->dimension] ?? $top->dimension, (string) $top->recommendation)
            : 'Sem dados suficientes para decidir o foco; rodar comprehend e registrar a ideia.';

        $queue = [];
        foreach (array_slice($scored, 0, 8) as $row) {
            /** @var AiVentureQuestionAnswer $a */
            $a = $row['answer'];
            $queue[] = [
                'question_id' => $a->question_id,
                'dimension' => $a->dimension,
                'status' => $a->status,
                'priority_score' => round($row['score'], 3),
                'why' => $a->severity_if_blind,
                'action' => $a->recommendation,
                'answer' => \Illuminate\Support\Str::limit((string) $a->answer, 200),
            ];
        }

        $dataGaps = $this->dataGaps($answers);

        $focusAnswers = $this->focusAnswers($stage, $focusStatement, $top, $queue);

        return [
            'focus' => [
                'headline' => $focusStatement,
                'top_question_id' => $top?->question_id,
                'top_dimension' => $top?->dimension,
                'decision_queue' => $queue,
            ],
            'data_gaps' => $dataGaps,
            'focus_answers' => $focusAnswers,
        ];
    }

    private function score(AiVentureQuestionAnswer $answer, string $stage): float
    {
        $severity = self::SEVERITY_WEIGHT[$answer->severity_if_blind] ?? 1.0;
        $dimWeight = $this->dimensionStageWeight($answer->dimension, $stage);
        $actionability = $this->actionability($answer);

        return round($severity * $dimWeight * $actionability, 3);
    }

    private function dimensionStageWeight(string $dimension, string $stage): float
    {
        $early = in_array($stage, ['S0', 'S1'], true);

        return match ($dimension) {
            VentureQuestionCatalog::DIM_RISK => 1.5,
            VentureQuestionCatalog::DIM_HEALTH => 1.3,
            VentureQuestionCatalog::DIM_PROBLEM => $early ? 1.4 : 0.8,
            VentureQuestionCatalog::DIM_USERS => $early ? 1.3 : 1.0,
            VentureQuestionCatalog::DIM_MONETIZATION => $early ? 0.7 : 1.3,
            VentureQuestionCatalog::DIM_GROWTH => $early ? 0.6 : 1.4,
            VentureQuestionCatalog::DIM_RETENTION => $early ? 0.5 : 1.4,
            VentureQuestionCatalog::DIM_FINANCE => $early ? 0.5 : 1.1,
            VentureQuestionCatalog::DIM_PRODUCT => 1.0,
            VentureQuestionCatalog::DIM_COMPETITION => 0.9,
            VentureQuestionCatalog::DIM_EXECUTION => 1.0,
            default => 0.8,
        };
    }

    private function actionability(AiVentureQuestionAnswer $answer): float
    {
        $hasFindings = is_array($answer->evidence_refs) && $answer->evidence_refs !== [];
        $concern = $hasFindings && in_array($answer->dimension, [
            VentureQuestionCatalog::DIM_RISK,
            VentureQuestionCatalog::DIM_HEALTH,
        ], true);

        return match ($answer->status) {
            AiVentureQuestionAnswer::STATUS_ANSWERED => $concern ? 1.6 : ($hasFindings ? 1.0 : 0.5),
            AiVentureQuestionAnswer::STATUS_PARTIAL => 0.6,
            AiVentureQuestionAnswer::STATUS_BLOCKED_INTERNAL => 0.5,
            AiVentureQuestionAnswer::STATUS_BLOCKED_EXTERNAL => 0.4,
            default => 0.5,
        };
    }

    /**
     * Group blocked_external questions by the source that would unlock them —
     * the explicit roadmap to autonomy (wire source -> N questions answerable).
     *
     * @param  array<int,AiVentureQuestionAnswer>  $answers
     * @return array<int,array<string,mixed>>
     */
    private function dataGaps(array $answers): array
    {
        $bySource = [];
        foreach ($answers as $answer) {
            if ($answer->status !== AiVentureQuestionAnswer::STATUS_BLOCKED_EXTERNAL) {
                continue;
            }
            $source = (string) ($answer->external_source ?? 'external');
            $bySource[$source][] = $answer->question_id;
        }

        $gaps = [];
        foreach ($bySource as $source => $questionIds) {
            $gaps[] = [
                'source' => $source,
                'label' => VentureQuestionCatalog::EXTERNAL_SOURCES[$source] ?? $source,
                'unlocks_questions' => count($questionIds),
                'question_ids' => $questionIds,
            ];
        }

        usort($gaps, static fn ($a, $b): int => $b['unlocks_questions'] <=> $a['unlocks_questions']);

        return $gaps;
    }

    /**
     * Build the DIM_FOCUS answers (Q-FOC-001/002) from the decision.
     *
     * @param  array<int,array<string,mixed>>  $queue
     * @return array<int,array{question:array<string,mixed>,answer:array<string,mixed>}>
     */
    private function focusAnswers(string $stage, string $focusStatement, ?AiVentureQuestionAnswer $top, array $queue): array
    {
        $catalog = new VentureQuestionCatalog;
        $byId = [];
        foreach ($catalog->all() as $q) {
            $byId[$q['id']] = $q;
        }

        $milestone = match ($stage) {
            'S0' => 'validar o problema e ligar uma oportunidade real',
            'S1' => 'chegar à primeira receita (S2)',
            'S2' => 'chegar a 1M de receita (S3)',
            'S3' => 'chegar a 10M com LTV/CAC ≥ 3 (S4)',
            'S4' => 'chegar a 100M / liderança de categoria (S5)',
            default => 'defender a categoria e a eficiência de capital',
        };
        $lever = $top?->recommendation ?? 'fechar o gate de maior leverage do estágio atual.';

        $answers = [];
        if (isset($byId['Q-FOC-001'])) {
            $answers[] = [
                'question' => $byId['Q-FOC-001'],
                'answer' => [
                    'status' => AiVentureQuestionAnswer::STATUS_ANSWERED,
                    'answer' => 'A coisa mais importante agora é: '.$focusStatement,
                    'confidence' => 0.7,
                    'evidence_kind' => 'inferred',
                    'evidence_refs' => array_slice(array_map(fn ($q) => ['question_id' => $q['question_id']], $queue), 0, 3),
                    'recommendation' => $lever,
                    'priority_score' => 99.0,
                ],
            ];
        }
        if (isset($byId['Q-FOC-002'])) {
            $answers[] = [
                'question' => $byId['Q-FOC-002'],
                'answer' => [
                    'status' => AiVentureQuestionAnswer::STATUS_ANSWERED,
                    'answer' => "Próximo marco: {$milestone}. Alavanca: {$lever}",
                    'confidence' => 0.6,
                    'evidence_kind' => 'inferred',
                    'evidence_refs' => null,
                    'recommendation' => $lever,
                    'priority_score' => 90.0,
                ],
            ];
        }

        return $answers;
    }
}
