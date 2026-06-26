<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Campaign;

use App\Models\AtlasLoopCampaign;
use App\Services\Ai\AutonomousEvolution\AtlasLoopProviderCircuitBreaker;

/**
 * Cost/breaker governor extracted from {@see AtlasLoopCampaignSupervisor}.
 *
 * Owns the cost-budget throttle (reduce scenarios near the cap) and the provider
 * circuit-breaker record/ask concern. Every method is byte-identical to the
 * supervisor bodies they replace — the supervisor now delegates here.
 */
final class AtlasLoopCampaignCostGovernor
{
    /**
     * The L5-7 governor is not a separate router/runtime. It is the campaign's
     * existing cost budget interpreted before each grind: near the cap, reduce
     * scenarios; at/over the cap, the existing budgetStopReason() stops the loop.
     *
     * @return array<string,mixed>
     */
    public function costGovernorDecision(AtlasLoopCampaign $campaign, ?int $scenarios): array
    {
        $baseScenarios = max(1, (int) ($scenarios ?? config('atlas.loop.scenarios_per_task', 3)));
        $cfg = (array) config('atlas.loop.cost_governor', []);
        $enabled = (bool) ($cfg['enabled'] ?? false);
        $minScenarios = max(1, (int) ($cfg['min_scenarios_per_task'] ?? 1));
        $maxCents = max(0, (int) $campaign->max_usd_cents);
        $spendCents = max(0, (int) $campaign->spend_usd_cents);

        $base = [
            'schema_version' => 'atlas.loop.cost_governor_decision.v1',
            'enabled' => $enabled,
            'status' => $enabled ? 'monitoring' : 'disabled',
            'action' => 'none',
            'spend_usd_cents' => $spendCents,
            'max_usd_cents' => $maxCents,
            'spend_pct' => $maxCents > 0 ? round(($spendCents / $maxCents) * 100, 2) : null,
            'base_scenarios_per_task' => $baseScenarios,
            'effective_scenarios_per_task' => $baseScenarios,
            'min_scenarios_per_task' => $minScenarios,
        ];

        if (! $enabled) {
            return $base;
        }
        if ($maxCents <= 0) {
            return array_replace($base, [
                'status' => 'no_cost_cap_configured',
                'reason' => 'campaign_max_usd_cents_zero',
            ]);
        }

        $throttlePct = max(0.0, min(100.0, (float) ($cfg['throttle_at_pct'] ?? 80.0)));
        $spendPct = (float) $base['spend_pct'];
        if ($spendPct >= 100.0) {
            return array_replace($base, [
                'status' => 'over_cap',
                'action' => 'pause_on_cost_cap',
                'effective_scenarios_per_task' => $minScenarios,
                'reason' => 'budget_stop_reason_cost_cap_will_apply',
            ]);
        }
        if ($spendPct >= $throttlePct && $baseScenarios > $minScenarios) {
            return array_replace($base, [
                'status' => 'throttled',
                'action' => 'reduce_scenarios_per_task',
                'effective_scenarios_per_task' => $minScenarios,
                'threshold_pct' => $throttlePct,
            ]);
        }

        return array_replace($base, [
            'threshold_pct' => $throttlePct,
        ]);
    }

    /**
     * @param  array<string,mixed>  $result
     */
    public function spendCentsFromResult(array $result): int
    {
        if (is_numeric($result['cost_cents'] ?? null) && (int) $result['cost_cents'] > 0) {
            return (int) $result['cost_cents'];
        }
        if (is_numeric($result['cost_estimate_usd'] ?? null) && (float) $result['cost_estimate_usd'] > 0.0) {
            return max(1, (int) ceil((float) $result['cost_estimate_usd'] * 100));
        }

        return 0;
    }

    /**
     * @param  list<array<string,mixed>>  $settled
     */
    public function spendCentsFromWorkerSummaries(array $settled): int
    {
        $spend = 0;
        foreach ($settled as $summary) {
            $result = is_array($summary['result'] ?? null) ? $summary['result'] : [];
            $spend += $this->spendCentsFromResult($result);
        }

        return $spend;
    }

    /**
     * §4 Record one grind outcome's provider-health into the circuit-breaker. Flag-OFF ⇒ no-op (the breaker is
     * never written, so it can never open ⇒ byte-identical). A provider-down grind (no winner, 0 scenarios)
     * increments the streak; a healthy grind resets it.
     *
     * @param  array<string,mixed>  $outcome
     */
    public function recordBreaker(string $campaignId, array $outcome): void
    {
        if (! (bool) config('atlas.loop.provider_circuit_breaker_enabled', false)) {
            return;
        }
        (new AtlasLoopProviderCircuitBreaker)->record($campaignId, $outcome);
    }

    /** §4 Should the loop pause NOW because the provider has been down for >= threshold consecutive grinds? */
    public function breakerWantsPause(AtlasLoopCampaign $campaign): bool
    {
        if (! (bool) config('atlas.loop.provider_circuit_breaker_enabled', false)) {
            return false;
        }
        $threshold = max(1, (int) config('atlas.loop.provider_circuit_breaker_threshold', 5));

        return (new AtlasLoopProviderCircuitBreaker)->isOpen((string) $campaign->id, $threshold);
    }
}
