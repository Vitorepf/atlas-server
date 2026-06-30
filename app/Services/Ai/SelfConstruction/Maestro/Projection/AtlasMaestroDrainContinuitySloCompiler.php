<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Maestro\Projection;

/**
 * Pure compiler — translates queue depth, servable_now, active workers, throughput samples,
 * give_back rate, and claim latency into deterministic SLO verdicts for continuous muscle
 * operation. Tells the Maestro whether the queue will keep workers fed (servable supply),
 * roughly when it will drain, whether give_backs are amplifying the backlog, and whether
 * claim latency is within budget.
 *
 * INPUT FACTS (each optional, sane defaults applied):
 *   queue_depth:            int     — pending task_packet count (default 0)
 *   servable_now:           int     — claimable-right-now count (default 0)
 *   active_workers:         int     — currently-active worker count (default 0)
 *   throughput_samples:     list<float> — recent completed-tasks-per-hour samples (default [])
 *   give_back_rate:         float 0-1 — fraction of attempts that give_back (default 0.0)
 *   claim_latency_seconds:  float   — average time-to-claim (default 0.0)
 *
 * OUTPUT (FACTS only — no scalar quality score):
 *   {schema_version, status, verdicts, blockers}
 *
 * PURE / DETERMINISTIC / NO I/O.
 */
final class AtlasMaestroDrainContinuitySloCompiler
{
    public const SCHEMA = 'atlas.self_construction.maestro.drain_continuity_slo.v1';

    public const STATUS_HEALTHY = 'healthy';

    public const STATUS_AT_RISK = 'at_risk';

    public const STATUS_CRITICAL = 'critical';

    private const CLAIM_LATENCY_SLO_SECONDS = 30.0;

    private const GIVE_BACK_RATE_RISK_THRESHOLD = 0.3;

    private const DRAIN_ETA_AT_RISK_HOURS = 24.0;

    /**
     * @param  array<string,mixed>  $facts
     * @return array<string,mixed>
     */
    public function compile(array $facts): array
    {
        $queueDepth = max(0, (int) ($facts['queue_depth'] ?? 0));
        $servableNow = max(0, (int) ($facts['servable_now'] ?? 0));
        $activeWorkers = max(0, (int) ($facts['active_workers'] ?? 0));
        $throughputSamples = array_values(array_filter(
            (array) ($facts['throughput_samples'] ?? []),
            static fn (mixed $v): bool => is_numeric($v),
        ));
        $giveBackRate = max(0.0, min(1.0, (float) ($facts['give_back_rate'] ?? 0.0)));
        $claimLatencySeconds = max(0.0, (float) ($facts['claim_latency_seconds'] ?? 0.0));

        $avgThroughput = $throughputSamples !== []
            ? array_sum(array_map('floatval', $throughputSamples)) / count($throughputSamples)
            : 0.0;

        $blockers = [];
        $verdicts = [];

        // 1. Servable supply: can workers always find claimable work?
        if ($queueDepth > 0 && $servableNow === 0) {
            $supplyVerdict = 'starved';
            $blockers[] = 'queue_starved_no_servable_work';
        } elseif ($activeWorkers > 0 && $servableNow < $activeWorkers) {
            $supplyVerdict = 'at_risk';
        } else {
            $supplyVerdict = 'healthy';
        }
        $verdicts['servable_supply'] = $supplyVerdict;

        // 2. Drain ETA: roughly when will the current backlog be cleared at observed throughput.
        if ($avgThroughput > 0.0) {
            $etaHours = $queueDepth / $avgThroughput;
            $drainVerdict = $etaHours > self::DRAIN_ETA_AT_RISK_HOURS ? 'at_risk' : 'healthy';
            if ($drainVerdict === 'at_risk') {
                $blockers[] = 'drain_eta_exceeds_slo_window';
            }
        } elseif ($queueDepth > 0) {
            $etaHours = null;
            $drainVerdict = 'unknown_zero_throughput';
            $blockers[] = 'zero_throughput_with_pending_queue';
        } else {
            $etaHours = 0.0;
            $drainVerdict = 'healthy';
        }
        $verdicts['drain_eta'] = $drainVerdict;
        $verdicts['drain_eta_hours'] = $etaHours;

        // 3. Give_back amplification: a high give_back rate re-queues work and inflates the
        //    effective backlog beyond what queue_depth alone shows.
        $giveBackVerdict = $giveBackRate >= self::GIVE_BACK_RATE_RISK_THRESHOLD ? 'amplifying_risk' : 'healthy';
        if ($giveBackVerdict === 'amplifying_risk') {
            $blockers[] = 'give_back_rate_amplifying_queue';
        }
        $verdicts['give_back_amplification'] = $giveBackVerdict;

        // 4. Claim latency SLO.
        $claimVerdict = $claimLatencySeconds > self::CLAIM_LATENCY_SLO_SECONDS ? 'breached' : 'within_slo';
        if ($claimVerdict === 'breached') {
            $blockers[] = 'claim_latency_slo_breached';
        }
        $verdicts['claim_latency'] = $claimVerdict;

        $status = match (true) {
            $supplyVerdict === 'starved' => self::STATUS_CRITICAL,
            $blockers !== [] => self::STATUS_AT_RISK,
            default => self::STATUS_HEALTHY,
        };

        return [
            'schema_version' => self::SCHEMA,
            'status' => $status,
            'verdicts' => $verdicts,
            'blockers' => $blockers,
        ];
    }
}
