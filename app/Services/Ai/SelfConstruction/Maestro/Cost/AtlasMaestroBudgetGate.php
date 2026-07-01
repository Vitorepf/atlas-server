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

    public const DECISION_ALLOW = 'allow';

    public const DECISION_ALLOW_REDUCED = 'allow_reduced';

    public const DECISION_BLOCK = 'block';

    private const RETRY_LOOP_RATE_THRESHOLD = 0.30;

    private const WASTED_TOKEN_RATE_THRESHOLD = 0.40;

    private const REDUCED_BUDGET_MULTIPLIER = 0.5;

    /** AC2: below this, a task carries too little structural value to justify any waste signal at all. */
    private const LOW_STRUCTURAL_VALUE_THRESHOLD = 0.20;

    /** AC3: proof_demand/criticality/expected_unlock must ALL clear this floor to justify a high-cost route. */
    private const HIGH_VALUE_JUSTIFICATION_THRESHOLD = 0.70;

    /** AC4: overage_cents buckets for the cost_band field. */
    private const COST_BAND_LOW_CEILING = 500;

    private const COST_BAND_MEDIUM_CEILING = 5000;

    public function __construct(private readonly AtlasMaestroCostAggregator $aggregator) {}

    /**
     * Waste-aware routing gate: blocks or reduces the routing budget when recent attempts show
     * high token waste or retry-loop churn, unless the current task is a proven high-leverage
     * repair that already demonstrates it reduces the waste, not adds to it.
     *
     * @param  array{
     *   retry_loop_rate?:float, wasted_token_rate?:float,
     *   high_leverage_repair?:bool, verified_waste_reduction_proof?:bool,
     * }  $facts
     * @return array{budget_decision:string, budget_multiplier:float, blocked_reasons:list<string>, allowed_exception_reason:?string}
     */
    public function evaluateWasteAwareRouting(array $facts): array
    {
        $retryLoopRate = max(0.0, min(1.0, (float) ($facts['retry_loop_rate'] ?? 0.0)));
        $wastedTokenRate = max(0.0, min(1.0, (float) ($facts['wasted_token_rate'] ?? 0.0)));
        $highLeverageRepair = (bool) ($facts['high_leverage_repair'] ?? false);
        $wasteReductionProof = (bool) ($facts['verified_waste_reduction_proof'] ?? false);
        // AC2: default is neutral (0.5) so callers who don't yet report structural value see no change.
        $structuralValueScore = max(0.0, min(1.0, (float) ($facts['structural_value_score'] ?? 0.5)));

        $blockedReasons = [];
        if ($retryLoopRate > self::RETRY_LOOP_RATE_THRESHOLD) {
            $blockedReasons[] = 'retry_loop_rate_exceeded';
        }
        if ($wastedTokenRate > self::WASTED_TOKEN_RATE_THRESHOLD) {
            $blockedReasons[] = 'wasted_token_rate_exceeded';
        }
        // AC2: even a mild waste signal is not tolerable when the task carries almost no
        // structural value — low value never earns the benefit of the doubt on waste.
        if (($retryLoopRate > 0.0 || $wastedTokenRate > 0.0) && $structuralValueScore < self::LOW_STRUCTURAL_VALUE_THRESHOLD) {
            $blockedReasons[] = 'low_structural_value_with_waste_signal';
        }

        if ($blockedReasons === []) {
            return [
                'budget_decision' => self::DECISION_ALLOW,
                'budget_multiplier' => 1.0,
                'blocked_reasons' => [],
                'allowed_exception_reason' => null,
            ];
        }

        // A high-leverage repair is only exempted when it carries its OWN proof of reducing
        // waste — leverage claims alone never bypass a demonstrated waste problem.
        if ($highLeverageRepair && $wasteReductionProof) {
            return [
                'budget_decision' => self::DECISION_ALLOW_REDUCED,
                'budget_multiplier' => self::REDUCED_BUDGET_MULTIPLIER,
                'blocked_reasons' => $blockedReasons,
                'allowed_exception_reason' => 'high_leverage_repair_with_verified_waste_reduction_proof',
            ];
        }

        return [
            'budget_decision' => self::DECISION_BLOCK,
            'budget_multiplier' => 0.0,
            'blocked_reasons' => $blockedReasons,
            'allowed_exception_reason' => null,
        ];
    }

    /**
     * @param  array<string,mixed>  $facts  Optional per-call context: cost_cents, fact_source.
     * @return array<string,mixed>
     */
    public function decide(string $taskPacketId, string $provider, string $taskClass, ?string $cycleId, array $facts = []): array
    {
        $env = fn (string $gate, string $reason, ?string $window, int $overageCents, string $factSource = 'aggregator'): array =>
            $this->envelope($gate, $reason, $window, $overageCents, $taskPacketId, $provider, $taskClass, $factSource);

        // Atlas-native zero-cost exemption: local execution has no provider spend. Requires an
        // explicit atlas_native_capability_proof fact — a bare zero-cost claim alone is not proof
        // and must not bypass budget windows (that would be a fake zero-cost claim).
        if ($provider === 'atlas_native' && array_key_exists('cost_cents', $facts) && (int) $facts['cost_cents'] === 0
            && ! empty($facts['atlas_native_capability_proof'])) {
            return $env(self::GATE_ALLOW, 'atlas_native_zero_cost', null, 0, 'native_declared');
        }

        $budgets = $this->loadBudgets();
        $enforce = $this->enforceModeOn();

        // AC3: a high-cost route past its budget window is only ever allowed through when
        // proof_demand, criticality AND expected_unlock all clear the bar, backed by an explicit
        // verified proof fact — same "claims alone never bypass" discipline as the native exemption.
        $proofDemand = max(0.0, min(1.0, (float) ($facts['proof_demand_score'] ?? 0.0)));
        $criticality = max(0.0, min(1.0, (float) ($facts['criticality_score'] ?? 0.0)));
        $expectedUnlock = max(0.0, min(1.0, (float) ($facts['expected_unlock_score'] ?? 0.0)));
        $verifiedHighValueProof = (bool) ($facts['verified_high_value_route_proof'] ?? false);
        $highValueJustified = $verifiedHighValueProof
            && $proofDemand >= self::HIGH_VALUE_JUSTIFICATION_THRESHOLD
            && $criticality >= self::HIGH_VALUE_JUSTIFICATION_THRESHOLD
            && $expectedUnlock >= self::HIGH_VALUE_JUSTIFICATION_THRESHOLD;

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
            return $env(self::GATE_ALLOW, 'no_facts', null, 0, 'none');
        }

        // Check each window in order; first overage wins.
        if ($cycleId !== null && isset($budgets[self::WINDOW_PER_CYCLE]) && isset($byCycle[$cycleId])) {
            $sum = (int) ($byCycle[$cycleId]['sum_cost_cents'] ?? 0);
            $budget = (int) $budgets[self::WINDOW_PER_CYCLE];
            if ($budget > 0 && $sum > $budget) {
                if ($highValueJustified) {
                    return $env(self::GATE_ALLOW, 'high_value_route_justified', self::WINDOW_PER_CYCLE, 0);
                }

                return $env($enforce ? self::GATE_REFUSE : self::GATE_ADVISE, 'cycle_budget_exceeded', self::WINDOW_PER_CYCLE, $sum - $budget);
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
                if ($highValueJustified) {
                    return $env(self::GATE_ALLOW, 'high_value_route_justified', self::WINDOW_PER_PROVIDER_PER_DAY, 0);
                }

                return $env($enforce ? self::GATE_REFUSE : self::GATE_ADVISE, 'provider_day_budget_exceeded', self::WINDOW_PER_PROVIDER_PER_DAY, $sum - $budget);
            }
        }

        if (isset($budgets[self::WINDOW_PER_TASK_CLASS_PER_DAY], $byTaskClass[$taskClass])) {
            $sum = (int) ($byTaskClass[$taskClass]['sum_cost_cents'] ?? 0);
            $budget = (int) $budgets[self::WINDOW_PER_TASK_CLASS_PER_DAY];
            if ($budget > 0 && $sum > $budget) {
                if ($highValueJustified) {
                    return $env(self::GATE_ALLOW, 'high_value_route_justified', self::WINDOW_PER_TASK_CLASS_PER_DAY, 0);
                }

                return $env($enforce ? self::GATE_REFUSE : self::GATE_ADVISE, 'task_class_day_budget_exceeded', self::WINDOW_PER_TASK_CLASS_PER_DAY, $sum - $budget);
            }
        }

        if ($byCycle === [] && $byProvider === [] && $byTaskClass === []) {
            return $env(self::GATE_ALLOW, 'no_facts', null, 0, 'none');
        }

        return $env(self::GATE_ALLOW, 'within_budget', null, 0);
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
    private function envelope(string $gate, string $reason, ?string $window, int $overageCents, string $taskPacketId = '', string $provider = '', string $taskClass = '', string $factSource = 'aggregator'): array
    {
        $blocked = in_array($gate, [self::GATE_ADVISE, self::GATE_REFUSE], true);

        return [
            'gate' => $gate,
            'reason' => $reason,
            'window' => $window,
            'overage_cents' => $overageCents,
            'task_packet_id' => $taskPacketId,
            'provider' => $provider,
            'task_class' => $taskClass,
            'fact_source' => $factSource,
            // AC4: provider class, cost band, waste reason, fallback route.
            'provider_class' => $this->providerClass($provider),
            'cost_band' => $this->costBand($overageCents),
            'waste_reason' => $blocked ? $reason : null,
            'fallback_route' => $blocked ? 'atlas_native' : null,
        ];
    }

    private function providerClass(string $provider): string
    {
        return $provider === 'atlas_native' ? 'zero_cost' : 'paid_provider';
    }

    private function costBand(int $overageCents): string
    {
        return match (true) {
            $overageCents <= 0 => 'none',
            $overageCents <= self::COST_BAND_LOW_CEILING => 'low',
            $overageCents <= self::COST_BAND_MEDIUM_CEILING => 'medium',
            default => 'high',
        };
    }
}
