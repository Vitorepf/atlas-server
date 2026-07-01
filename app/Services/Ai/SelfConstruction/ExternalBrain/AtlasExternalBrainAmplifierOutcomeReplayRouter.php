<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Pure router: maps completed task outcomes to amplifier learning sinks.
 *
 * Outcomes with evidence_count < MIN_EVIDENCE are ignored — anecdotal single
 * samples must not update routing.
 *
 * Sink routing table (original amplifier sinks):
 *   commit_success → scaffold_selection, model_tier_routing
 *   give_back      → scaffold_selection, promotion_gates
 *   poison         → promotion_gates, regression_cases
 *   weak_evidence  → regression_cases
 *   high_value     → scaffold_selection, model_tier_routing, promotion_gates
 *
 * Learning-loop sinks (AC: proxy/no-delta must NOT feed positive learning):
 *   proxy_success       → rollback_signal, heldout_benchmark_update
 *   no_capability_delta → rollback_signal, heldout_benchmark_update
 *   heldout_failure     → heldout_benchmark_update, frontier_escalation_signal
 *   frontier_candidate  → scaffold_variant_learning, frontier_escalation_signal
 *
 * Unknown outcome_type → ignored (reason: unknown_outcome_type).
 *
 * Every routed outcome also gets a concrete `action` instead of passive sink
 * telemetry alone:
 *   rejection_rule            → poison / proxy_success / no_capability_delta,
 *                                or ANY outcome carrying regression_flags
 *   escalation_policy_update  → heldout_failure / frontier_candidate
 *   scaffold_patch            → outcome clears every learning_promotion gate
 *   replay_case               → everything else (needs investigation first)
 */
final class AtlasExternalBrainAmplifierOutcomeReplayRouter
{
    public const SCHEMA = 'atlas.external_brain.amplifier_outcome_replay_router.v1';

    public const MIN_EVIDENCE = 3;

    public const ACTION_REPLAY_CASE              = 'replay_case';
    public const ACTION_SCAFFOLD_PATCH            = 'scaffold_patch';
    public const ACTION_ESCALATION_POLICY_UPDATE  = 'escalation_policy_update';
    public const ACTION_REJECTION_RULE            = 'rejection_rule';

    private const SINK_MAP = [
        // original amplifier sinks
        'commit_success'      => ['scaffold_selection', 'model_tier_routing'],
        'give_back'           => ['scaffold_selection', 'promotion_gates'],
        'poison'              => ['promotion_gates', 'regression_cases'],
        'weak_evidence'       => ['regression_cases'],
        'high_value'          => ['scaffold_selection', 'model_tier_routing', 'promotion_gates'],
        // learning-loop sinks — proxy/no-delta must NOT feed positive learning
        'proxy_success'       => ['rollback_signal', 'heldout_benchmark_update'],
        'no_capability_delta' => ['rollback_signal', 'heldout_benchmark_update'],
        'heldout_failure'     => ['heldout_benchmark_update', 'frontier_escalation_signal'],
        'frontier_candidate'  => ['scaffold_variant_learning', 'frontier_escalation_signal'],
    ];

    /**
     * @param  array<string,mixed>  $input  task_outcomes list
     * @return array<string,mixed>
     */
    public function route(array $input): array
    {
        $outcomes = is_array($input['task_outcomes'] ?? null) ? $input['task_outcomes'] : [];

        $routedUpdates = [];
        $ignoredOutcomes = [];
        $affectedScaffoldSet = [];
        $affectedTierSet = [];
        $regressionCaseCandidates = [];
        $learningPromotionCandidates = [];

        foreach ($outcomes as $outcome) {
            $id = (string) ($outcome['outcome_id'] ?? 'unknown');
            $type = (string) ($outcome['outcome_type'] ?? '');
            $scaffold = (string) ($outcome['scaffold_variant'] ?? '');
            $tier = (string) ($outcome['model_tier'] ?? '');
            $evidenceCount = max(0, (int) ($outcome['evidence_count'] ?? 0));

            if ($evidenceCount < self::MIN_EVIDENCE) {
                $ignoredOutcomes[] = ['outcome_id' => $id, 'reason' => 'low_evidence'];

                continue;
            }

            if (! array_key_exists($type, self::SINK_MAP)) {
                $ignoredOutcomes[] = ['outcome_id' => $id, 'reason' => 'unknown_outcome_type'];

                continue;
            }

            $sinks = self::SINK_MAP[$type];

            // learning_promotion_candidates: positive amplifier learning (scaffold promotion) must
            // clear ALL five gates — sink eligibility, real capability delta, no proxy, held-out
            // pass, and no regression flags.
            $capabilityDelta = (float) ($outcome['capability_delta'] ?? 0.0);
            $proxyDetected = (bool) ($outcome['proxy_detected'] ?? false);
            $heldoutPassed = (bool) ($outcome['heldout_passed'] ?? false);
            $regressionFlags = is_array($outcome['regression_flags'] ?? null) ? $outcome['regression_flags'] : [];

            $promotionBlockers = [];
            if (! in_array('scaffold_selection', $sinks, true)) {
                $promotionBlockers[] = 'sink_excludes_scaffold_selection';
            }
            if ($capabilityDelta <= 0.0) {
                $promotionBlockers[] = 'capability_delta_not_positive';
            }
            if ($proxyDetected) {
                $promotionBlockers[] = 'proxy_detected';
            }
            if (! $heldoutPassed) {
                $promotionBlockers[] = 'heldout_not_passed';
            }
            if ($regressionFlags !== []) {
                $promotionBlockers[] = 'regression_flags_present';
            }

            $eligibleForPromotion = $promotionBlockers === [];

            $routedUpdates[] = [
                'outcome_id' => $id,
                'outcome_type' => $type,
                'sinks' => $sinks,
                'scaffold_variant' => $scaffold,
                'model_tier' => $tier,
                'action' => $this->classifyAction($type, $eligibleForPromotion, $regressionFlags),
            ];

            $learningPromotionCandidates[] = [
                'outcome_id' => $id,
                'outcome_type' => $type,
                'capability_delta' => $capabilityDelta,
                'proxy_detected' => $proxyDetected,
                'heldout_passed' => $heldoutPassed,
                'regression_flags' => $regressionFlags,
                'eligible_for_promotion' => $eligibleForPromotion,
                'promotion_blockers' => $promotionBlockers,
            ];

            if ($scaffold !== '') {
                $affectedScaffoldSet[$scaffold] = true;
            }
            if ($tier !== '') {
                $affectedTierSet[$tier] = true;
            }
            if (in_array('regression_cases', $sinks, true)) {
                $regressionCaseCandidates[] = [
                    'outcome_id' => $id,
                    'outcome_type' => $type,
                    'scaffold_variant' => $scaffold,
                    'model_tier' => $tier,
                ];
            }
        }

        return [
            'schema_version' => self::SCHEMA,
            'routed_updates' => $routedUpdates,
            'ignored_outcomes' => $ignoredOutcomes,
            'affected_scaffolds' => array_keys($affectedScaffoldSet),
            'affected_model_tiers' => array_keys($affectedTierSet),
            'regression_case_candidates' => $regressionCaseCandidates,
            'learning_promotion_candidates' => $learningPromotionCandidates,
        ];
    }

    /** @param  string[]  $regressionFlags */
    private function classifyAction(string $type, bool $eligibleForPromotion, array $regressionFlags): string
    {
        if ($regressionFlags !== []) {
            return self::ACTION_REJECTION_RULE;
        }

        return match ($type) {
            'poison', 'proxy_success', 'no_capability_delta' => self::ACTION_REJECTION_RULE,
            'heldout_failure', 'frontier_candidate' => self::ACTION_ESCALATION_POLICY_UPDATE,
            default => $eligibleForPromotion ? self::ACTION_SCAFFOLD_PATCH : self::ACTION_REPLAY_CASE,
        };
    }
}
