<?php

declare(strict_types=1);

namespace App\Services\Ai\Caching;

use App\Models\AiJob;

/**
 * Reusable provider-invocation cost sentinel — the spread-anywhere counterpart to
 * the per-cache {@see AiCallCostGuard}, so the proven pre-cost model can guard the
 * execution stacks (Forge, RealExecution, Frontier, Hermes) that today call
 * `$provider->run()` directly without any ceiling.
 *
 * Two deliberate safety properties:
 *   - Default-INERT: both thresholds come from config and default to 0 (disabled),
 *     so adopting the sentinel changes NO behaviour until the operator sets a
 *     number. soft_warn is telemetry only; the hard gate stays off until a positive
 *     `hard_gate_units` is configured (the operator's spend-ceiling decision).
 *   - Never throws: it RETURNS an assessment (hard_blocked flag) the caller routes
 *     around, rather than throwing mid-run — antifragile, the same choice the
 *     conductor turn-budget makes.
 *
 * It composes {@see AiCallCostGuard::evaluate()} (the deterministic, local pre-cost
 * estimate); it does not re-implement cost math.
 */
final class AtlasProviderCostSentinel
{
    public const SCHEMA_VERSION = 'atlas.ai.provider_cost_sentinel.v1';

    public function __construct(
        private readonly AiCallCostGuard $guard,
    ) {}

    /**
     * @return array{schema_version:string,evaluated:bool,soft_warn:bool,hard_blocked:bool,pre_cost_units:float,soft_threshold_units:float,hard_threshold_units:float,flow_id:?string,risk_level:?string}
     */
    public function assess(AiJob $job, string $prompt): array
    {
        $soft = (float) config('atlas.ai.cost_sentinel.soft_warn_units', 0.0);
        $hard = (float) config('atlas.ai.cost_sentinel.hard_gate_units', 0.0);

        $eval = $this->guard->evaluate($job, $prompt, $soft, $hard);

        // The hard gate only bites when the operator has set a positive ceiling.
        $hardBlocked = $hard > 0.0 && (bool) ($eval['hard_exceeded'] ?? false);

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'evaluated' => true,
            'soft_warn' => (bool) ($eval['soft_warn'] ?? false),
            'hard_blocked' => $hardBlocked,
            'pre_cost_units' => (float) ($eval['pre_cost_units'] ?? 0.0),
            'soft_threshold_units' => $soft,
            'hard_threshold_units' => $hard,
            'flow_id' => isset($eval['flow_id']) ? (string) $eval['flow_id'] : null,
            'risk_level' => isset($eval['risk_level']) ? (string) $eval['risk_level'] : null,
        ];
    }
}
