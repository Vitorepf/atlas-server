<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Responds to quota stalls — when verified candidates fall below requested target — by producing
 * concrete next-investigation plans drawn from the ambition escalation ladder and research pattern
 * planner rather than inventing filler.
 *
 * INPUT stall_state:
 *   { verified_count:int, requested_target:int,
 *     escalation_state:{ attempted_modes?:list<string>, evidence_by_mode?:array<string,list<string>>,
 *                        wave_number?:int, wave_yield?:float } }
 *
 * OUTPUT:
 *   { schema, honest_exhausted, investigations:list<Investigation>, next_mode, stall_gap,
 *     modes_with_evidence, modes_remaining? }
 *
 * Investigation:
 *   { investigation_id, mode, idea, source_categories:list<string>,
 *     expected_leverage, stop_conditions:list<string>, comparison_questions:list<string> }
 *
 * HONEST_EXHAUSTED guard: delegated entirely to AtlasExternalBrainAmbitionEscalationPolicy —
 * returns honest_exhausted=true only when all 6 ladder modes carry recorded evidence.
 * PURE / DETERMINISTIC. No I/O.
 */
final class AtlasExternalBrainBreakthroughPlanner
{
    public const SCHEMA = 'atlas.external_brain.breakthrough_planner.v1';

    private AtlasExternalBrainAmbitionEscalationPolicy $escalationPolicy;

    private AtlasExternalBrainResearchPatternPlan $researchPlan;

    public function __construct(
        ?AtlasExternalBrainAmbitionEscalationPolicy $escalationPolicy = null,
        ?AtlasExternalBrainResearchPatternPlan $researchPlan = null,
    ) {
        $this->escalationPolicy = $escalationPolicy ?? new AtlasExternalBrainAmbitionEscalationPolicy;
        $this->researchPlan = $researchPlan ?? new AtlasExternalBrainResearchPatternPlan;
    }

    /**
     * @param  array{
     *   verified_count?:int,
     *   requested_target?:int,
     *   escalation_state?:array<string,mixed>,
     * }  $stallState
     * @return array<string,mixed>
     */
    public function plan(array $stallState): array
    {
        $verified = max(0, (int) ($stallState['verified_count'] ?? 0));
        $target = max(1, (int) ($stallState['requested_target'] ?? 1));
        $escalationState = is_array($stallState['escalation_state'] ?? null) ? $stallState['escalation_state'] : [];

        $gap = max(0, $target - $verified);

        $escalation = $this->escalationPolicy->decide($escalationState);

        if ($escalation['honest_exhausted']) {
            return [
                'schema' => self::SCHEMA,
                'honest_exhausted' => true,
                'investigations' => [],
                'next_mode' => AtlasExternalBrainAmbitionEscalationPolicy::MODE_HONEST_EXHAUSTED,
                'stall_gap' => $gap,
                'modes_with_evidence' => $escalation['modes_with_evidence'],
            ];
        }

        $nextMode = $escalation['next_mode'];
        $idea = $this->modeIdea($nextMode, $gap);
        $research = $this->researchPlan->plan($idea);

        $investigations = [[
            'investigation_id' => $nextMode.':gap:'.$gap,
            'mode' => $nextMode,
            'idea' => $idea,
            'source_categories' => array_keys($research['source_categories']),
            'expected_leverage' => $this->expectedLeverage($nextMode),
            'stop_conditions' => $this->stopConditions($nextMode, $gap),
            'comparison_questions' => $research['comparison_questions'],
        ]];

        return [
            'schema' => self::SCHEMA,
            'honest_exhausted' => false,
            'investigations' => $investigations,
            'next_mode' => $nextMode,
            'stall_gap' => $gap,
            'modes_with_evidence' => $escalation['modes_with_evidence'],
            'modes_remaining' => $escalation['modes_remaining'],
        ];
    }

    private function modeIdea(string $mode, int $gap): string
    {
        return match ($mode) {
            AtlasExternalBrainAmbitionEscalationPolicy::MODE_CONTRACT_MISMATCH =>
                "interface and implementation contract drift causing {$gap} missing verified candidates",
            AtlasExternalBrainAmbitionEscalationPolicy::MODE_CROSS_DOMAIN_PATTERN =>
                "proven domain patterns transferable to fill {$gap} candidate gap",
            AtlasExternalBrainAmbitionEscalationPolicy::MODE_RESEARCH_BACKED_DESIGN =>
                "research-grounded design proposals to produce {$gap} verified candidates",
            AtlasExternalBrainAmbitionEscalationPolicy::MODE_ARCHITECTURE_SIMPLIFICATION =>
                "critical-path complexity removable to unlock {$gap} candidate slots",
            AtlasExternalBrainAmbitionEscalationPolicy::MODE_RUNTIME_HEALTH =>
                "observability and recovery gaps that explain {$gap} unverified candidates",
            AtlasExternalBrainAmbitionEscalationPolicy::MODE_CERTIFICATION_GAP =>
                "implemented-but-uncertified slices accounting for {$gap} missing certified candidates",
            default => "breakthrough strategies for {$gap}-candidate stall",
        };
    }

    private function expectedLeverage(string $mode): string
    {
        return match ($mode) {
            AtlasExternalBrainAmbitionEscalationPolicy::MODE_CONTRACT_MISMATCH,
            AtlasExternalBrainAmbitionEscalationPolicy::MODE_CROSS_DOMAIN_PATTERN,
            AtlasExternalBrainAmbitionEscalationPolicy::MODE_ARCHITECTURE_SIMPLIFICATION => 'high',
            default => 'medium',
        };
    }

    /** @return list<string> */
    private function stopConditions(string $mode, int $gap): array
    {
        $quota = max(1, (int) ceil($gap * 0.5));

        return [
            "verified_count_increases_by_at_least_{$quota}",
            'investigation_produced_at_least_one_grounded_evidence_entry',
            match ($mode) {
                AtlasExternalBrainAmbitionEscalationPolicy::MODE_CONTRACT_MISMATCH =>
                    'no_more_interface_implementation_drift_found',
                AtlasExternalBrainAmbitionEscalationPolicy::MODE_CROSS_DOMAIN_PATTERN =>
                    'pattern_transferred_or_confirmed_inapplicable',
                AtlasExternalBrainAmbitionEscalationPolicy::MODE_RESEARCH_BACKED_DESIGN =>
                    'research_evidence_cited_and_atlas_adaptation_hypothesis_recorded',
                AtlasExternalBrainAmbitionEscalationPolicy::MODE_ARCHITECTURE_SIMPLIFICATION =>
                    'critical_path_complexity_delta_measured_and_recorded',
                AtlasExternalBrainAmbitionEscalationPolicy::MODE_RUNTIME_HEALTH =>
                    'observability_gap_closed_or_confirmed_not_contributing',
                AtlasExternalBrainAmbitionEscalationPolicy::MODE_CERTIFICATION_GAP =>
                    'uncertified_slices_enumerated_or_confirmed_none',
                default => 'mode_evidence_recorded',
            },
        ];
    }
}
