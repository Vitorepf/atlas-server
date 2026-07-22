<?php

namespace App\Services\Ai\Cognitive\Dreyfus;

use App\Services\Ai\Kernel\Slo\KernelSloProbe;

class DreyfusPedagogyResolver
{
    public function __construct(
        private readonly DreyfusOverlayRepository $overlays,
        private readonly DreyfusEvidenceAggregator $aggregator,
        private readonly KernelSloProbe $slo,
    ) {}

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function resolve(array $input): array
    {
        return $this->slo->measure('cognitive.dreyfus.resolve', function () use ($input): array {
            $topic = trim((string) ($input['topic'] ?? $input['skill'] ?? $input['objective'] ?? 'unknown'));
            $domain = trim((string) ($input['domain'] ?? 'learning')) ?: 'learning';
            $flow = trim((string) ($input['flow'] ?? 'learning.plan')) ?: 'learning.plan';
            $knowledgeNodeId = trim((string) ($input['knowledge_node_id'] ?? '')) ?: $this->overlays->nodeIdForTopic($topic);
            $requested = $this->requestedStage($input['dreyfus_stage_target'] ?? $input['dreyfus_stage'] ?? 'auto');
            $overlay = $this->overlays->find($knowledgeNodeId, $domain);
            $aggregate = $overlay === null ? $this->aggregator->aggregate($knowledgeNodeId, $domain) : null;
            $level = $requested ?? (int) ($overlay['current_level'] ?? $aggregate['current_level'] ?? 1);

            return [
                'schema_version' => 'atlas.cognitive.dreyfus_pedagogy_resolution.v1',
                'status' => 'resolved',
                'knowledge_node_id' => $knowledgeNodeId,
                'domain' => $domain,
                'flow' => $flow,
                'dreyfus_stage_resolved' => max(1, min(5, $level)),
                'pedagogy_mode_resolved' => $this->mode(max(1, min(5, $level))),
                'confidence' => (float) ($overlay['confidence'] ?? $aggregate['confidence'] ?? ($requested ? 0.70 : 0.50)),
                'source' => $requested ? 'operator_requested' : ($overlay ? 'dreyfus_overlay' : 'evidence_aggregate_fallback'),
                'overlay' => $overlay,
                'evidence_aggregate' => $aggregate,
                'scaffolding_policy' => $this->scaffoldingPolicy(max(1, min(5, $level))),
                'selection_explanation' => [
                    'primary_signals' => array_values(array_filter([
                        $overlay ? 'dreyfus_overlay' : null,
                        $aggregate ? 'evidence_aggregate' : null,
                        $requested ? 'operator_requested_stage' : null,
                    ])),
                    'fallback_used' => $overlay === null && $requested === null,
                    'confidence_band' => $this->confidenceBand((float) ($overlay['confidence'] ?? $aggregate['confidence'] ?? 0.50)),
                ],
            ];
        }, [
            'domain' => (string) ($input['domain'] ?? 'learning'),
            'flow' => (string) ($input['flow'] ?? 'learning.plan'),
            'surface_id' => (string) ($input['surface_id'] ?? 'atlas_learning'),
        ]);
    }

    /**
     * @return array<string,mixed>
     */
    public function promptPolicy(array $resolution): array
    {
        $stage = (int) ($resolution['dreyfus_stage_resolved'] ?? 1);

        return [
            'schema_version' => 'atlas.cognitive.dreyfus_prompt_policy.v1',
            'stage' => $stage,
            'mode' => $this->mode($stage),
            'rules' => match ($stage) {
                1 => ['explicit_rules', 'glossary_first', 'single_step_checkpoint', 'avoid_assumed_context'],
                2 => ['worked_example', 'short_exercise', 'immediate_feedback', 'name_common_traps'],
                3 => ['scenario_practice', 'compare_tradeoffs', 'ask_for_rationale', 'targeted_feedback'],
                4 => ['ill_structured_case', 'adversarial_feedback', 'minimal_preface', 'metric_first'],
                default => ['ambiguous_frontier_case', 'teach_back', 'transfer_requirement', 'principle_compression'],
            },
            'forbidden' => match ($stage) {
                1 => ['skip_glossary', 'use_advanced_jargon_without_definition', 'large_open_ended_prompt'],
                4, 5 => ['basic_tutorial_preface', 'over_scaffold_every_step'],
                default => ['claim_mastery_without_evidence'],
            },
        ];
    }

    private function requestedStage(mixed $value): ?int
    {
        if (is_numeric($value)) {
            $level = (int) $value;

            return $level >= 1 && $level <= 5 ? $level : null;
        }

        return null;
    }

    private function mode(int $level): string
    {
        return match ($level) {
            1 => 'novato',
            2 => 'iniciante_avancado',
            3 => 'competente',
            4 => 'expert',
            default => 'master',
        };
    }

    /**
     * @return array<string,mixed>
     */
    private function scaffoldingPolicy(int $level): array
    {
        return [
            'step_by_step' => $level <= 2,
            'glossary_required' => $level === 1,
            'worked_examples_required' => $level <= 2,
            'adversarial_feedback' => $level >= 4,
            'transfer_proof_required' => $level >= 3,
            'operator_can_dispute' => true,
        ];
    }

    private function confidenceBand(float $confidence): string
    {
        return match (true) {
            $confidence >= 0.80 => 'high',
            $confidence >= 0.55 => 'medium',
            default => 'low',
        };
    }
}
