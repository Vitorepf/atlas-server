<?php

declare(strict_types=1);

namespace App\Services\Ai\EngineeringKernel\Adapters;

use App\Services\Ai\EngineeringKernel\BudgetMeter;
use App\Services\Ai\SelfConstruction\Maestro\Cost\AtlasMaestroCostAggregator;
use App\Services\Ai\SelfConstruction\Maestro\Cost\AtlasMaestroCostLedger;

/**
 * Third Engineering Kernel adapter: pure delegation to the existing, already-proven
 * AtlasMaestroCostLedger (measure/record) and AtlasMaestroCostAggregator (summarize) — zero new
 * validation or aggregation rules. Gives Atlas Dev, Atlas Forge and Autonomos one shared
 * BudgetMeter mechanism surface while each runtime keeps its own flow.
 */
final class MaestroCostBudgetMeterAdapter implements BudgetMeter
{
    public function __construct(
        private readonly AtlasMaestroCostLedger $ledger,
        private readonly AtlasMaestroCostAggregator $aggregator,
    ) {}

    /**
     * @param  array<string,mixed>  $usage
     * @return array<string,mixed>
     */
    public function measure(array $usage): array
    {
        // append() returns null on malformed input (skipped, never thrown) — coerced to []
        // only to satisfy this interface's non-null return type, not a new validation rule.
        return $this->ledger->append($usage) ?? [];
    }

    /**
     * @param  array<string,mixed>  $filters  {by?: 'task_class'|'provider'|'cycle', cycle_id?: string}
     * @return array<string,mixed>
     */
    public function summarize(array $filters = []): array
    {
        $cycleId = isset($filters['cycle_id']) ? (string) $filters['cycle_id'] : null;
        $by = (string) ($filters['by'] ?? 'task_class');

        return match ($by) {
            'provider' => $this->aggregator->aggregateByProvider($cycleId),
            'cycle' => $this->aggregator->aggregateByCycle($cycleId),
            default => $this->aggregator->aggregateByTaskClass($cycleId),
        };
    }
}
