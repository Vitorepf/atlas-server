<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\TaskFabric;

/**
 * Pure retirement queue. Accepts a list of task-spec candidates and classifies
 * each as: retired | protected | ineligible.
 *
 * Retirement eligibility (ANY condition suffices):
 *   - value_estimate < value_threshold (default 0.2)
 *   - is_duplicate === true
 *   - is_stale === true
 *
 * Critical-chain guard (AC2):
 *   If the candidate's id appears in critical_dependency_chains, retirement is
 *   REFUSED regardless of eligibility → emitted in protected[] with reason
 *   'on_critical_dependency_chain'.
 *
 * Processing order:
 *   1. Check critical chain → protected (fail-closed, blocks retirement).
 *   2. Check retirement eligibility → retired.
 *   3. Otherwise → ineligible (value is fine, nothing to retire).
 *
 * NO process execution, NO filesystem, NO providers. DETERMINISTIC.
 */
final class AtlasTaskFabricLowValueRetirementQueue
{
    public const SCHEMA = 'atlas.task_fabric.low_value_retirement_queue.v1';

    private const DEFAULT_VALUE_THRESHOLD = 0.2;

    /**
     * @param  array<string,mixed>  $facts
     * @return array<string,mixed>
     */
    public function evaluate(array $facts): array
    {
        $candidates      = is_array($facts['candidates'] ?? null) ? $facts['candidates'] : [];
        $criticalChainIds = array_flip(array_map('strval', (array) ($facts['critical_dependency_chains'] ?? [])));
        $valueThreshold  = (float) ($facts['value_threshold'] ?? self::DEFAULT_VALUE_THRESHOLD);

        $retired    = [];
        $protected  = [];
        $ineligible = [];

        foreach ($candidates as $c) {
            $id           = (string) ($c['id'] ?? '');
            $valueEst     = (float) ($c['value_estimate'] ?? 1.0);
            $isDuplicate  = (bool) ($c['is_duplicate'] ?? false);
            $isStale      = (bool) ($c['is_stale'] ?? false);

            $entry = ['id' => $id, 'value_estimate' => $valueEst];

            // AC2: critical dependency chain is an absolute block.
            if (isset($criticalChainIds[$id])) {
                $protected[] = array_merge($entry, ['protection_reason' => 'on_critical_dependency_chain']);
                continue;
            }

            // Retirement eligibility.
            if ($valueEst < $valueThreshold || $isDuplicate || $isStale) {
                $reason = match (true) {
                    $isDuplicate             => 'duplicate',
                    $isStale                 => 'stale',
                    default                  => 'low_value',
                };
                $retired[] = array_merge($entry, ['retirement_reason' => $reason]);
                continue;
            }

            $ineligible[] = $entry;
        }

        return [
            'schema_version'   => self::SCHEMA,
            'retired'          => $retired,
            'protected'        => $protected,
            'ineligible'       => $ineligible,
            'total_retired'    => count($retired),
            'total_protected'  => count($protected),
            'value_threshold'  => $valueThreshold,
        ];
    }
}
