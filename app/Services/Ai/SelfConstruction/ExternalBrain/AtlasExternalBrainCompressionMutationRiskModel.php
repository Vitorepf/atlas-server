<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Pure proof-backed risk model: computes the required mutation-proof guard set for a
 * compression action from ACTION TYPE, branch coverage, capability criticality and consumer
 * count — never a fixed guard checklist. A high-risk action missing any required guard is
 * held, never allowed to proceed on a partial proof.
 *
 * BASE GUARDS by action_type:
 *   delete   -> mutation_test, regression_suite
 *   merge    -> mutation_test, integration_test
 *   simplify -> mutation_test
 *
 * ADDITIONAL GUARDS (appended when the fact applies):
 *   branch_coverage < BRANCH_COVERAGE_FLOOR      -> branch_coverage_uplift
 *   capability_criticality in {high, critical}   -> critical_capability_review
 *   consumer_count >= CONSUMER_COUNT_HIGH_RISK   -> consumer_impact_assessment
 *
 * HIGH RISK when capability_criticality in {high, critical}, OR consumer_count is high, OR
 * action_type === 'delete' (a delete is irreversible without proof).
 *
 * DECISION: hold whenever any required guard is absent from guards_present — go only when
 * every required guard is present. missing_guards always names exactly which guards are
 * missing, never a generic "insufficient proof" message.
 *
 * Pure. No I/O, no provider calls, deterministic.
 */
final class AtlasExternalBrainCompressionMutationRiskModel
{
    public const SCHEMA = 'atlas.external_brain.compression_mutation_risk_model.v1';

    public const DECISION_GO = 'go';

    public const DECISION_HOLD = 'hold';

    private const BRANCH_COVERAGE_FLOOR = 0.70;

    private const CONSUMER_COUNT_HIGH_RISK = 3;

    private const BASE_GUARDS = [
        'delete' => ['mutation_test', 'regression_suite'],
        'merge' => ['mutation_test', 'integration_test'],
        'simplify' => ['mutation_test'],
    ];

    private const CRITICAL_LEVELS = ['high', 'critical'];

    /**
     * @param  array{actions?: list<array<string,mixed>>}  $input
     * @return array<string,mixed>
     */
    public function evaluate(array $input): array
    {
        $actions = is_array($input['actions'] ?? null) ? $input['actions'] : [];

        $results = [];
        $counts = [self::DECISION_GO => 0, self::DECISION_HOLD => 0];

        foreach ($actions as $action) {
            if (! is_array($action)) {
                continue;
            }
            $entry = $this->evaluateOne($action);
            $results[] = $entry;
            $counts[$entry['decision']]++;
        }

        return [
            'schema' => self::SCHEMA,
            'actions' => $results,
            'summary' => [
                'total' => count($results),
                'go_count' => $counts[self::DECISION_GO],
                'hold_count' => $counts[self::DECISION_HOLD],
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $action
     * @return array<string,mixed>
     */
    private function evaluateOne(array $action): array
    {
        $actionId = (string) ($action['action_id'] ?? '');
        $actionType = strtolower(trim((string) ($action['action_type'] ?? '')));
        if (! array_key_exists($actionType, self::BASE_GUARDS)) {
            $actionType = 'simplify';
        }
        $branchCoverage = max(0.0, min(1.0, (float) ($action['branch_coverage'] ?? 0.0)));
        $capabilityCriticality = strtolower(trim((string) ($action['capability_criticality'] ?? 'low')));
        $consumerCount = max(0, (int) ($action['consumer_count'] ?? 0));
        $guardsPresent = array_values(array_map('strval', (array) ($action['guards_present'] ?? [])));

        $requiredGuards = self::BASE_GUARDS[$actionType];
        if ($branchCoverage < self::BRANCH_COVERAGE_FLOOR) {
            $requiredGuards[] = 'branch_coverage_uplift';
        }
        if (in_array($capabilityCriticality, self::CRITICAL_LEVELS, true)) {
            $requiredGuards[] = 'critical_capability_review';
        }
        if ($consumerCount >= self::CONSUMER_COUNT_HIGH_RISK) {
            $requiredGuards[] = 'consumer_impact_assessment';
        }
        $requiredGuards = array_values(array_unique($requiredGuards));
        sort($requiredGuards, SORT_STRING);

        $highRisk = in_array($capabilityCriticality, self::CRITICAL_LEVELS, true)
            || $consumerCount >= self::CONSUMER_COUNT_HIGH_RISK
            || $actionType === 'delete';

        $missingGuards = array_values(array_diff($requiredGuards, $guardsPresent));
        sort($missingGuards, SORT_STRING);

        $decision = $missingGuards === [] ? self::DECISION_GO : self::DECISION_HOLD;

        return [
            'action_id' => $actionId,
            'action_type' => $actionType,
            'decision' => $decision,
            'high_risk' => $highRisk,
            'required_guards' => $requiredGuards,
            'missing_guards' => $missingGuards,
            'branch_coverage' => $branchCoverage,
            'capability_criticality' => $capabilityCriticality,
            'consumer_count' => $consumerCount,
        ];
    }
}
