<?php

declare(strict_types=1);

namespace App\Services\Ai\Caching;

use App\Models\AiJob;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\Reliable24hLoopRunnerService;
use App\Services\Ai\Telemetry\AiCostEstimator;
use App\Services\Ai\Tokens\AtlasTokenEconomyBudgetPolicyService;

/**
 * Per-operation cost guard for a single (would-be-real) provider call.
 *
 * It composes — does NOT re-implement — the token-economy pre-cost model
 * ({@see AtlasTokenEconomyBudgetPolicyService::estimateCost()}), the SAME
 * normalized-unit function the cache's secondary savings signal uses, so the
 * guard and the savings speak one currency. Two thresholds, both config-driven:
 *
 *   - SOFT-WARN (telemetry only, never blocks): the early warning a call is
 *     getting expensive. Degrades nothing.
 *   - HARD-GATE (refuses): throws {@see AiCallCostExceededException} BEFORE the
 *     inner provider is invoked, so no spend happens.
 *
 * Boundary contract: this is the PER-OPERATION grain. It sits UNDER the
 * loop-level STATUS_BUDGET / STATUS_PROVIDER_WASTE stop owned by
 * {@see Reliable24hLoopRunnerService}
 * — it never touches that runner's budgetStop()/budgets(); the thrown exception
 * simply surfaces as a blocked cycle the loop already knows how to classify.
 *
 * Pre-cost is computed deterministically and locally (no provider call):
 *   - input tokens = chars/4 over the prompt (the canonical
 *     {@see AiCostEstimator::estimateTokens()} heuristic),
 *   - output budget = the flow/risk output_tokens_max from the token-economy
 *     budget table (an upper bound — the guard is intentionally conservative on
 *     the spend it is about to authorize).
 */
final class AiCallCostGuard
{
    public const OUTCOME_SOFT_WARN = 'soft_warn';

    public const OUTCOME_HARD_GATE = 'hard_gate_throw';

    public function __construct(
        private readonly AtlasTokenEconomyBudgetPolicyService $tokenEconomy,
    ) {}

    /**
     * Evaluate the pre-cost of the call about to happen.
     *
     * @return array{
     *   pre_cost_units: float,
     *   estimated_input_tokens: int,
     *   estimated_output_tokens: int,
     *   soft_threshold_units: float,
     *   hard_threshold_units: float,
     *   soft_warn: bool,
     *   hard_exceeded: bool,
     *   flow_id: string,
     *   risk_level: string
     * }
     */
    public function evaluate(AiJob $job, string $prompt, float $softThresholdUnits, float $hardThresholdUnits): array
    {
        $flowId = $this->resolveFlow($job);
        $riskLevel = $this->resolveRisk($job);

        $inputTokens = $this->estimateTokens($prompt);
        $outputBudget = (int) ($this->tokenEconomy->budget($flowId, $riskLevel)['output_tokens_max'] ?? 0);

        $preCost = $this->tokenEconomy->estimateCost($inputTokens, $outputBudget);

        return [
            'pre_cost_units' => $preCost,
            'estimated_input_tokens' => $inputTokens,
            'estimated_output_tokens' => $outputBudget,
            'soft_threshold_units' => $softThresholdUnits,
            'hard_threshold_units' => $hardThresholdUnits,
            // Soft is a >= warning; hard is a strict > refusal. A call exactly at
            // the hard threshold proceeds — only a call OVER it is refused.
            'soft_warn' => $softThresholdUnits > 0.0 && $preCost >= $softThresholdUnits,
            'hard_exceeded' => $hardThresholdUnits > 0.0 && $preCost > $hardThresholdUnits,
            'flow_id' => $flowId,
            'risk_level' => $riskLevel,
        ];
    }

    /**
     * Resolve a token-economy flow from the job. Defaults to the conservative
     * 'default' flow; the budget service itself falls back safely on any
     * unknown flow, so an unrecognized kind never throws.
     */
    private function resolveFlow(AiJob $job): string
    {
        $payloadFlow = data_get($job->payload, 'flow_id');
        if (is_string($payloadFlow) && $payloadFlow !== '' && AtlasTokenEconomyBudgetPolicyService::isValidFlow($payloadFlow)) {
            return $payloadFlow;
        }

        $kind = (string) ($job->kind ?? '');

        return match (true) {
            str_contains($kind, 'forge') => AtlasTokenEconomyBudgetPolicyService::FLOW_ATLAS_FORGE,
            str_contains($kind, 'research') => AtlasTokenEconomyBudgetPolicyService::FLOW_ATLAS_RESEARCH,
            str_contains($kind, 'dev') || str_contains($kind, 'code') => AtlasTokenEconomyBudgetPolicyService::FLOW_ATLAS_DEV,
            default => AtlasTokenEconomyBudgetPolicyService::FLOW_DEFAULT,
        };
    }

    private function resolveRisk(AiJob $job): string
    {
        $payloadRisk = data_get($job->payload, 'risk_level');
        if (is_string($payloadRisk) && AtlasTokenEconomyBudgetPolicyService::isValidRisk($payloadRisk)) {
            return $payloadRisk;
        }

        return AtlasTokenEconomyBudgetPolicyService::RISK_LOW;
    }

    private function estimateTokens(string $text): int
    {
        $text = trim($text);
        if ($text === '') {
            return 0;
        }

        return max(1, (int) ceil(mb_strlen($text) / 4));
    }
}
