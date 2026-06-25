<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Maestro\Cost;

/**
 * Decision primitive: REFUSE serving high-cost Maestro tasks when a budget window is exceeded.
 *
 * Reads ONLY from {@see AtlasMaestroCostAggregator}; never reads the ledger directly; never writes.
 *
 * Default mode is ADVISORY (`['gate' => 'advise', 'reason' => ..., 'window' => ..., 'overage_cents' => ...]`).
 * ENFORCE mode is gated by env `ATLAS_MAESTRO_BUDGET_GATE_ENFORCE`/config — in enforce mode, decide()
 * returns `gate='refuse'` with the same fact shape.
 *
 * Fail-OPEN on missing data or bad config → `['gate' => 'allow', 'reason' => 'no_facts']`. NEVER throws.
 */
final class AtlasMaestroBudgetGate
{
    public const GATE_ALLOW = 'allow';

    public const GATE_ADVISE = 'advise';

    public const GATE_REFUSE = 'refuse';

    public const WINDOW_PER_CYCLE = 'per_cycle_cents';

    public const WINDOW_PER_PROVIDER_PER_DAY = 'per_provider_per_day_cents';

    public const WINDOW_PER_TASK_CLASS_PER_DAY = 'per_task_class_per_day_cents';

    public function __construct(private readonly AtlasMaestroCostAggregator $aggregator) {}

    /**
     * @return array<string,mixed>
     */
    public function decide(string $taskPacketId, string $provider, string $taskClass, ?string $cycleId): array
    {
        $budgets = $this->loadBudgets();
        $enforce = $this->enforceModeOn();

        $byCycle = [];
        $byProvider = [];
        $byTaskClass = [];
        try {
            if ($cycleId !== null) {
                $byCycle = $this->aggregator->aggregateByCycle($cycleId);
            }
            $byProvider = $this->aggregator->aggregateByProvider();
            $byTaskClass = $this->aggregator->aggregateByTaskClass();
        } catch (\Throwable) {
            return $this->envelope(self::GATE_ALLOW, 'no_facts', null, 0);
        }

        // Check each window in order; first overage wins.
        if ($cycleId !== null && isset($budgets[self::WINDOW_PER_CYCLE]) && isset($byCycle[$cycleId])) {
            $sum = (int) ($byCycle[$cycleId]['sum_cost_cents'] ?? 0);
            $budget = (int) $budgets[self::WINDOW_PER_CYCLE];
            if ($budget > 0 && $sum > $budget) {
                return $this->envelope($enforce ? self::GATE_REFUSE : self::GATE_ADVISE, 'cycle_budget_exceeded', self::WINDOW_PER_CYCLE, $sum - $budget);
            }
        }

        if (isset($budgets[self::WINDOW_PER_PROVIDER_PER_DAY])) {
            $sum = 0;
            foreach ($byProvider as $groupKey => $row) {
                if (str_starts_with($groupKey, $provider.':')) {
                    $sum += (int) ($row['sum_cost_cents'] ?? 0);
                }
            }
            $budget = (int) $budgets[self::WINDOW_PER_PROVIDER_PER_DAY];
            if ($budget > 0 && $sum > $budget) {
                return $this->envelope($enforce ? self::GATE_REFUSE : self::GATE_ADVISE, 'provider_day_budget_exceeded', self::WINDOW_PER_PROVIDER_PER_DAY, $sum - $budget);
            }
        }

        if (isset($budgets[self::WINDOW_PER_TASK_CLASS_PER_DAY], $byTaskClass[$taskClass])) {
            $sum = (int) ($byTaskClass[$taskClass]['sum_cost_cents'] ?? 0);
            $budget = (int) $budgets[self::WINDOW_PER_TASK_CLASS_PER_DAY];
            if ($budget > 0 && $sum > $budget) {
                return $this->envelope($enforce ? self::GATE_REFUSE : self::GATE_ADVISE, 'task_class_day_budget_exceeded', self::WINDOW_PER_TASK_CLASS_PER_DAY, $sum - $budget);
            }
        }

        if ($byCycle === [] && $byProvider === [] && $byTaskClass === []) {
            return $this->envelope(self::GATE_ALLOW, 'no_facts', null, 0);
        }

        return $this->envelope(self::GATE_ALLOW, 'within_budget', null, 0);
    }

    /**
     * @return array<string,int>
     */
    private function loadBudgets(): array
    {
        if (function_exists('config')) {
            $raw = config('atlas.maestro.cost.budgets');
            if (is_array($raw)) {
                return array_filter(array_map('intval', $raw), static fn (int $v): bool => $v >= 0);
            }
        }

        return [];
    }

    private function enforceModeOn(): bool
    {
        if (function_exists('config')) {
            $enforce = config('atlas.maestro.cost.budget_gate_enforce');
            if ($enforce !== null) {
                return (bool) $enforce;
            }
        }
        $env = getenv('ATLAS_MAESTRO_BUDGET_GATE_ENFORCE');
        if ($env !== false) {
            return in_array(strtolower((string) $env), ['1', 'true', 'on', 'yes'], true);
        }

        return false;
    }

    /**
     * @return array<string,mixed>
     */
    private function envelope(string $gate, string $reason, ?string $window, int $overageCents): array
    {
        return [
            'gate' => $gate,
            'reason' => $reason,
            'window' => $window,
            'overage_cents' => $overageCents,
        ];
    }
}
