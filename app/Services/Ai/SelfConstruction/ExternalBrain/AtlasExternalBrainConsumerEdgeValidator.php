<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * A proposed Autonomous task can pass every seed-quality and test gate while still producing an
 * output that no live Autonomous block ever consumes — green but useless. This validator demands
 * a concrete `downstream_consumer_path` (an ordered list of blocks the task's output flows
 * through) and proves the path actually terminates in a live consuming block: Task Fabric,
 * Queue, Maestro, Outcome Learning, Control Plane, or Prompt Contract.
 *
 * A task with no path is REJECTED as detached_task. A task whose path terminates in a
 * report-only sink (a dashboard, digest, log, or summary that nothing downstream reads) is
 * REJECTED as report_only_sink — storing a label is not consuming it. Only a path that ends in
 * one of the known live consumer blocks is accepted, and the exact consuming block is returned
 * so callers can cite it as proof.
 *
 * Pure PHP, deterministic, no I/O.
 * @unwired-until 2026-08-05 (Obra #7 W2: capability testada aguardando consumidor; triagem 2026-07-06)
 */
final class AtlasExternalBrainConsumerEdgeValidator
{
    public const SCHEMA = 'atlas.self_construction.external_brain.consumer_edge_validator.v1';

    /** @var list<string> */
    private const LIVE_CONSUMER_BLOCKS = [
        'task_fabric',
        'queue',
        'maestro',
        'outcome_learning',
        'control_plane',
        'prompt_contract',
    ];

    /** @var list<string> */
    private const REPORT_ONLY_SINKS = [
        'report',
        'dashboard',
        'digest',
        'log',
        'summary',
        'readonly_report',
    ];

    /**
     * @param  array{downstream_consumer_path?: list<string>}  $task
     * @return array{schema:string, consumer_edge_valid:bool, rejection_reason:?string, consuming_block:?string}
     */
    public function validate(array $task): array
    {
        $path = is_array($task['downstream_consumer_path'] ?? null)
            ? array_values(array_filter(array_map('strval', $task['downstream_consumer_path']), static fn (string $b) => trim($b) !== ''))
            : [];

        if ($path === []) {
            return $this->rejected('detached_task');
        }

        $terminal = strtolower(trim((string) end($path)));

        if ($this->matchesAny($terminal, self::REPORT_ONLY_SINKS)) {
            return $this->rejected('report_only_sink');
        }

        if (! $this->matchesAny($terminal, self::LIVE_CONSUMER_BLOCKS)) {
            return $this->rejected('detached_task');
        }

        return [
            'schema' => self::SCHEMA,
            'consumer_edge_valid' => true,
            'rejection_reason' => null,
            'consuming_block' => $terminal,
        ];
    }

    /** @param  list<string>  $candidates */
    private function matchesAny(string $terminal, array $candidates): bool
    {
        foreach ($candidates as $candidate) {
            if ($terminal === $candidate || str_contains($terminal, $candidate)) {
                return true;
            }
        }

        return false;
    }

    private function rejected(string $reason): array
    {
        return [
            'schema' => self::SCHEMA,
            'consumer_edge_valid' => false,
            'rejection_reason' => $reason,
            'consuming_block' => null,
        ];
    }
}
