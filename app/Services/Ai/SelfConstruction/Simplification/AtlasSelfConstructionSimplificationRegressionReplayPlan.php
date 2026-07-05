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
        $action = strtolower(trim((string) ($input['action'] ?? '')));
        $publicCommandConsumers = array_values(array_unique(array_map('strval', (array) ($input['public_command_consumers'] ?? []))));
        $commandReplayExpectations = array_values(array_filter((array) ($input['command_replay_expectations'] ?? []), 'is_array'));
        // rollback_receipt_present defaults to rollback_plan_present so existing callers that only
        // ever supplied the plan flag keep exact prior behavior.
        $rollbackReceiptPresent = array_key_exists('rollback_receipt_present', $input)
            ? (bool) $input['rollback_receipt_present']
            : $rollbackPlanPresent;
        $isDeletionOrMerge = in_array($action, ['delete', 'merge'], true);

        $testCommands = $testsCoveringTargets !== []
            ? ['php artisan test '.implode(' ', $testsCoveringTargets)]
            : [];
        $commandReplayChecks = array_map(
            static fn (string $command): string => "replay_public_command:{$command}",
            $publicCommandConsumers,
        );

        $preChecks = array_values(array_filter(array_merge($testCommands, $commandReplayChecks, [$queueHealthGate])));
        $postChecks = $testCommands;
        $replayChecks = array_merge(
            array_map(
                static fn (string $organ): string => "replay_behavior_equivalence:{$organ}",
                $targetOrgans,
            ),
            $commandReplayChecks,
        );
        $acceptanceGates = [
            'behavior_equivalence_proven',
            'rollback_plan_present',
            'tests_green',
        ];
        if ($isDeletionOrMerge) {
            $acceptanceGates[] = 'public_command_replay_covered';
            $acceptanceGates[] = 'rollback_receipt_present';
        }

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

        // Deletion/merge candidates carry the highest blast radius: touched tests, public command
        // replay coverage, a proven behavior-parity check, and a rollback receipt (distinct from a
        // mere "plan") are ALL required, or the candidate is blocked with missing_replay_gate rather
        // than treated as safe.
        if ($isDeletionOrMerge) {
            if ($publicCommandConsumers === []) {
                $notReadyReasons[] = 'no_public_command_replay_coverage';
            } elseif ($commandReplayExpectations === []) {
                $notReadyReasons[] = 'missing_command_replay_expectations';
            } else {
                foreach ($commandReplayExpectations as $expectation) {
                    $hasExitCode = array_key_exists('expected_exit_code', $expectation) && is_int($expectation['expected_exit_code']);
                    $hasOutputContractRefs = ! empty($expectation['output_contract_refs']);
                    if (! $hasExitCode || ! $hasOutputContractRefs) {
                        $notReadyReasons[] = 'incomplete_command_replay_expectation';
                        break;
                    }
                }
            }
            if (! $rollbackReceiptPresent) {
                $notReadyReasons[] = 'rollback_receipt_missing';
            }
            if ($notReadyReasons !== []) {
                $notReadyReasons[] = 'missing_replay_gate';
            }
        }

        $notReadyReasons = array_values(array_unique($notReadyReasons));

        // AC: critical path probes for callers, public commands, config bindings and failure cases.
        $callerPaths = array_values(array_unique(array_map('strval', (array) ($input['caller_paths'] ?? []))));
        $configBindings = array_values(array_unique(array_map('strval', (array) ($input['config_bindings'] ?? []))));
        $failureCases = array_values(array_unique(array_map('strval', (array) ($input['failure_cases'] ?? []))));

        $callerProbes = array_map(
            static fn (string $caller): string => "caller_probe:{$caller}",
            $callerPaths,
        );
        $commandProbes = $commandReplayChecks;
        $configProbes = array_map(
            static fn (string $config): string => "config_probe:{$config}",
            $configBindings,
        );
        $failureProbes = array_map(
            static fn (string $failure): string => "failure_probe:{$failure}",
            $failureCases,
        );

        $criticalPathProbes = array_values(array_filter(array_merge(
            $callerProbes,
            $commandProbes,
            $configProbes,
            $failureProbes,
        )));

        return [
            'schema' => self::SCHEMA,
            'ready' => $notReadyReasons === [],
            'pre_checks' => $preChecks,
            'post_checks' => $postChecks,
            'replay_checks' => $replayChecks,
            'acceptance_gates' => $acceptanceGates,
            'not_ready_reasons' => $notReadyReasons,
            'critical_path_probes' => $criticalPathProbes,
            'caller_probe' => $callerProbes,
            'command_probe' => $commandProbes,
            'config_probe' => $configProbes,
            'failure_probe' => $failureProbes,
        ];
    }
}
