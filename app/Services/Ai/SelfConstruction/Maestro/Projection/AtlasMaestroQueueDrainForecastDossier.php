<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Maestro\Projection;

/**
 * Pure dossier builder. Produces a compact, operator-safe summary of queue drain
 * risk so operators and the loop can decide whether to originate, unblock, or wait.
 *
 * INVARIANT (AC2): blocked packets and high-poison-risk packets are NEVER counted
 * as productive ready work. Only effective_ready (= ready − blocked − high_poison)
 * drives the drain_eta and productivity_risk estimates.
 *
 * Inputs:
 *   queue_health       — ready_count, blocked_count, claimed_count, total_count
 *   workload_projection — throughput_per_hour, risk
 *   blocked_plan        — blocked_count, next_action
 *   poison_risk         — high_risk_count, overall_risk ('low'|'medium'|'high')
 *
 * Outputs:
 *   drain_eta, productivity_risk, next_action, evidence_refs, effective_ready.
 *
 * Pure: no I/O, no side effects.
 */
final class AtlasMaestroQueueDrainForecastDossier
{
    public const SCHEMA = 'atlas.maestro.queue_drain_forecast_dossier.v1';

    public const RISK_LOW      = 'low';
    public const RISK_MEDIUM   = 'medium';
    public const RISK_HIGH     = 'high';
    public const RISK_CRITICAL = 'critical';

    public const ACTION_ORIGINATE   = 'originate';
    public const ACTION_UNBLOCK     = 'unblock';
    public const ACTION_MONITOR     = 'monitor';
    public const ACTION_DRAIN_POISON = 'drain_poison';

    private const REPLENISH_EFFECTIVE_THRESHOLD = 3;

    /**
     * @param  array{
     *   queue_health?: array{ready_count?:int, blocked_count?:int, claimed_count?:int, total_count?:int},
     *   workload_projection?: array{throughput_per_hour?:float, risk?:string},
     *   blocked_plan?: array{blocked_count?:int, next_action?:string},
     *   poison_risk?: array{high_risk_count?:int, overall_risk?:string},
     * }  $facts
     * @return array{
     *   schema:string, drain_eta:string, productivity_risk:string,
     *   next_action:string, evidence_refs:list<string>, effective_ready:int
     * }
     */
    public function compile(array $facts): array
    {
        $health     = is_array($facts['queue_health']        ?? null) ? $facts['queue_health']        : [];
        $projection = is_array($facts['workload_projection'] ?? null) ? $facts['workload_projection'] : [];
        $plan       = is_array($facts['blocked_plan']        ?? null) ? $facts['blocked_plan']        : [];
        $poison     = is_array($facts['poison_risk']         ?? null) ? $facts['poison_risk']         : [];

        $readyCount     = max(0, (int) ($health['ready_count']   ?? 0));
        $blockedCount   = max(0, (int) ($health['blocked_count'] ?? $plan['blocked_count'] ?? 0));
        $claimedCount   = max(0, (int) ($health['claimed_count'] ?? 0));
        $highPoisonCt   = max(0, (int) ($poison['high_risk_count'] ?? 0));
        $overallPoison  = (string) ($poison['overall_risk'] ?? self::RISK_LOW);
        $throughput     = max(0.0, (float) ($projection['throughput_per_hour'] ?? 0.0));
        $projectionRisk = (string) ($projection['risk'] ?? 'healthy');

        // AC2: effective_ready excludes blocked and high-poison-risk packets.
        $effectiveReady = max(0, $readyCount - $blockedCount - $highPoisonCt);

        // ── drain_eta ───────────────────────────────────────────────────────
        $drainEta = $this->computeDrainEta($effectiveReady, $throughput);

        // ── productivity_risk ────────────────────────────────────────────────
        $productivityRisk = $this->computeProductivityRisk(
            $effectiveReady, $blockedCount, $readyCount, $overallPoison, $projectionRisk,
        );

        // ── next_action ──────────────────────────────────────────────────────
        $nextAction = $this->computeNextAction(
            $effectiveReady, $blockedCount, $overallPoison, $projectionRisk,
        );

        // ── evidence_refs ────────────────────────────────────────────────────
        $evidenceRefs = $this->buildEvidenceRefs(
            $readyCount, $blockedCount, $claimedCount, $highPoisonCt, $overallPoison, $throughput, $effectiveReady,
        );

        return [
            'schema'            => self::SCHEMA,
            'drain_eta'         => $drainEta,
            'productivity_risk' => $productivityRisk,
            'next_action'       => $nextAction,
            'evidence_refs'     => $evidenceRefs,
            'effective_ready'   => $effectiveReady,
        ];
    }

    private function computeDrainEta(int $effectiveReady, float $throughput): string
    {
        if ($effectiveReady <= 0) {
            return 'queue_effectively_empty';
        }
        if ($throughput <= 0.0) {
            return 'unknown:no_throughput_data';
        }
        $etaHours = $effectiveReady / $throughput;
        if ($etaHours < 1.0) {
            return (int) round($etaHours * 60).'m';
        }
        if ($etaHours < 24.0) {
            return (int) round($etaHours).'h';
        }
        $days  = (int) ($etaHours / 24);
        $hours = (int) round($etaHours - $days * 24);

        return $days.'d'.($hours > 0 ? ' '.$hours.'h' : '');
    }

    private function computeProductivityRisk(
        int $effectiveReady,
        int $blockedCount,
        int $readyCount,
        string $overallPoison,
        string $projectionRisk,
    ): string {
        if ($effectiveReady <= 0) {
            return self::RISK_CRITICAL;
        }
        if ($overallPoison === self::RISK_HIGH || $effectiveReady < self::REPLENISH_EFFECTIVE_THRESHOLD) {
            return self::RISK_HIGH;
        }
        if ($projectionRisk === 'replenish_now' || ($readyCount > 0 && $blockedCount > (int) round($readyCount / 2))) {
            return self::RISK_MEDIUM;
        }

        return self::RISK_LOW;
    }

    private function computeNextAction(
        int $effectiveReady,
        int $blockedCount,
        string $overallPoison,
        string $projectionRisk,
    ): string {
        if ($overallPoison === self::RISK_HIGH) {
            return self::ACTION_DRAIN_POISON;
        }
        if ($blockedCount > 0 && $blockedCount >= $effectiveReady) {
            return self::ACTION_UNBLOCK;
        }
        if ($effectiveReady < self::REPLENISH_EFFECTIVE_THRESHOLD || $projectionRisk === 'replenish_now') {
            return self::ACTION_ORIGINATE;
        }

        return self::ACTION_MONITOR;
    }

    /** @return list<string> */
    private function buildEvidenceRefs(
        int $readyCount,
        int $blockedCount,
        int $claimedCount,
        int $highPoisonCt,
        string $overallPoison,
        float $throughput,
        int $effectiveReady,
    ): array {
        $refs = [];
        $refs[] = 'queue.ready:'.$readyCount;
        $refs[] = 'queue.blocked:'.$blockedCount;
        $refs[] = 'queue.claimed:'.$claimedCount;
        if ($highPoisonCt > 0) {
            $refs[] = 'poison.high_risk:'.$highPoisonCt;
        }
        $refs[] = 'poison.overall_risk:'.$overallPoison;
        if ($throughput > 0.0) {
            $refs[] = 'throughput.per_hour:'.number_format($throughput, 2);
        }
        $refs[] = 'effective_ready:'.$effectiveReady;

        return $refs;
    }
}
