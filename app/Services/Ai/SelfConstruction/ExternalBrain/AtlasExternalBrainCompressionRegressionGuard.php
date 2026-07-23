<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Invariant gate: a compression wave never gets to call itself a win just because the line
 * count shrank. This guard compares a BEFORE and AFTER snapshot across four release-readiness
 * floors — test count, docs sync, capability coverage, and worker yield — and approves release
 * only when every floor is preserved or improved. Any floor that regressed is HELD with the
 * exact violated floor name and a concrete repair action; a missing snapshot fails closed the
 * same way a regression does.
 *
 * Input contract:
 *   before: array{test_count?: int, docs_sync?: bool, capability_coverage?: float, worker_yield?: float}
 *   after:  array{test_count?: int, docs_sync?: bool, capability_coverage?: float, worker_yield?: float}
 *
 * A numeric floor regresses when after < before. The boolean docs_sync floor regresses only
 * when it goes from true (in sync) to false (drifted) — going from false to true is an
 * improvement, never a violation.
 *
 * Pure PHP, deterministic, no I/O.
 */
final class AtlasExternalBrainCompressionRegressionGuard
{
    public const SCHEMA = 'atlas.external_brain.compression_regression_guard.v1';

    public const DECISION_APPROVE = 'approve';
    public const DECISION_HOLD    = 'hold';

    private const NUMERIC_FLOORS = ['test_count', 'capability_coverage', 'worker_yield'];

    private const REPAIR_ACTION_BY_FLOOR = [
        'test_count'           => 'restore_removed_test_coverage',
        'docs_sync'            => 'resync_documentation_before_release',
        'capability_coverage'  => 'restore_capability_coverage_to_baseline',
        'worker_yield'         => 'investigate_worker_throughput_regression',
        'before_snapshot'      => 'supply_before_snapshot_before_release',
        'after_snapshot'       => 'supply_after_snapshot_before_release',
    ];

    /**
     * @param  array{before?: array<string,mixed>, after?: array<string,mixed>}  $facts
     * @return array{schema:string, decision:string, violated_floors:list<string>, required_repair_actions:list<string>}
     */
    public function evaluate(array $facts): array
    {
        $before = $facts['before'] ?? null;
        $after  = $facts['after'] ?? null;

        if (! is_array($before) || $before === []) {
            return $this->hold(['before_snapshot']);
        }
        if (! is_array($after) || $after === []) {
            return $this->hold(['after_snapshot']);
        }

        $violated = [];

        foreach (self::NUMERIC_FLOORS as $floor) {
            $beforeValue = (float) ($before[$floor] ?? 0.0);
            $afterValue  = (float) ($after[$floor] ?? 0.0);
            if ($afterValue < $beforeValue) {
                $violated[] = $floor;
            }
        }

        $docsBefore = (bool) ($before['docs_sync'] ?? true);
        $docsAfter  = (bool) ($after['docs_sync'] ?? true);
        if ($docsBefore && ! $docsAfter) {
            $violated[] = 'docs_sync';
        }

        if ($violated !== []) {
            return $this->hold($violated);
        }

        return [
            'schema'                   => self::SCHEMA,
            'decision'                 => self::DECISION_APPROVE,
            'violated_floors'          => [],
            'required_repair_actions'  => [],
        ];
    }

    /** @param  list<string>  $violated */
    private function hold(array $violated): array
    {
        $repairActions = [];
        foreach ($violated as $floor) {
            $repairActions[] = self::REPAIR_ACTION_BY_FLOOR[$floor] ?? "repair_{$floor}_regression";
        }

        return [
            'schema'                   => self::SCHEMA,
            'decision'                 => self::DECISION_HOLD,
            'violated_floors'          => $violated,
            'required_repair_actions'  => array_values(array_unique($repairActions)),
        ];
    }
}
