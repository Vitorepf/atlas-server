<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Simplification;

/**
 * Pure regression-replay planner for a simplification wave (organ merge or
 * retirement). Compiles the exact tests, replay checks, receipt checks, and
 * queue health gates required before and after the wave is accepted, so a
 * simplification never ships on "looks equivalent" alone.
 *
 * READY requires both:
 *   - behavior_equivalence_proven = true (an explicit equivalence proof, not assumed)
 *   - rollback_plan_present       = true (a concrete way back if the wave regresses)
 * Missing either marks the plan not_ready with the specific missing gates named.
 *
 * INPUT:
 *   {
 *     target_organs:                  list<string>
 *     tests_covering_targets?:        list<string> (default [])
 *     behavior_equivalence_proven?:   bool (default false)
 *     rollback_plan_present?:         bool (default false)
 *     queue_health_gate?:             string (default 'php artisan atlas:task self-heal --json')
 *   }
 *
 * OUTPUT:
 *   { schema, ready, pre_checks, post_checks, replay_checks, acceptance_gates, not_ready_reasons }
 *
 * Pure: no I/O, no side effects — it only compiles commands, never runs them.
 */
final class AtlasSelfConstructionSimplificationRegressionReplayPlan
{
    public const SCHEMA = 'atlas.self_construction.simplification_regression_replay_plan.v1';

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function compile(array $input): array
    {
        $targetOrgans = array_values(array_unique(array_map('strval', (array) ($input['target_organs'] ?? []))));
        $testsCoveringTargets = array_values(array_unique(array_map('strval', (array) ($input['tests_covering_targets'] ?? []))));
        $behaviorEquivalenceProven = (bool) ($input['behavior_equivalence_proven'] ?? false);
        $rollbackPlanPresent = (bool) ($input['rollback_plan_present'] ?? false);
        $queueHealthGate = (string) ($input['queue_health_gate'] ?? 'php artisan atlas:task:self-heal --json');

        $testCommands = $testsCoveringTargets !== []
            ? ['php artisan test '.implode(' ', $testsCoveringTargets)]
            : [];

        $preChecks = array_values(array_filter(array_merge($testCommands, [$queueHealthGate])));
        $postChecks = $testCommands;
        $replayChecks = array_map(
            static fn (string $organ): string => "replay_behavior_equivalence:{$organ}",
            $targetOrgans,
        );
        $acceptanceGates = [
            'behavior_equivalence_proven',
            'rollback_plan_present',
            'tests_green',
        ];

        $notReadyReasons = [];
        if (! $behaviorEquivalenceProven) {
            $notReadyReasons[] = 'behavior_equivalence_not_proven';
        }
        if (! $rollbackPlanPresent) {
            $notReadyReasons[] = 'rollback_plan_missing';
        }
        if ($testsCoveringTargets === []) {
            $notReadyReasons[] = 'no_tests_covering_targets';
        }

        return [
            'schema' => self::SCHEMA,
            'ready' => $notReadyReasons === [],
            'pre_checks' => $preChecks,
            'post_checks' => $postChecks,
            'replay_checks' => $replayChecks,
            'acceptance_gates' => $acceptanceGates,
            'not_ready_reasons' => $notReadyReasons,
        ];
    }
}
