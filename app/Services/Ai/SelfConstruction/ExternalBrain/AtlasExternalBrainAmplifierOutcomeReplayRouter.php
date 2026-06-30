<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Pure router: maps completed task outcomes to amplifier learning sinks
 * (scaffold_selection, model_tier_routing, promotion_gates, regression_cases).
 *
 * Outcomes with evidence_count < MIN_EVIDENCE are ignored — anecdotal single
 * samples must not update routing.
 *
 * Sink routing table:
 *   commit_success → scaffold_selection, model_tier_routing
 *   give_back      → scaffold_selection, promotion_gates
 *   poison         → promotion_gates, regression_cases
 *   weak_evidence  → regression_cases
 *   high_value     → scaffold_selection, model_tier_routing, promotion_gates
 *
 * Unknown outcome_type → ignored (reason: unknown_outcome_type).
 */
final class AtlasExternalBrainAmplifierOutcomeReplayRouter
{
    public const SCHEMA = 'atlas.external_brain.amplifier_outcome_replay_router.v1';

    public const MIN_EVIDENCE = 3;

    private const SINK_MAP = [
        'commit_success' => ['scaffold_selection', 'model_tier_routing'],
        'give_back' => ['scaffold_selection', 'promotion_gates'],
        'poison' => ['promotion_gates', 'regression_cases'],
        'weak_evidence' => ['regression_cases'],
        'high_value' => ['scaffold_selection', 'model_tier_routing', 'promotion_gates'],
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
            $routedUpdates[] = [
                'outcome_id' => $id,
                'outcome_type' => $type,
                'sinks' => $sinks,
                'scaffold_variant' => $scaffold,
                'model_tier' => $tier,
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
        ];
    }
}
