<?php

namespace App\Services\Ai\VentureFoundry\Success;

use App\Services\Ai\VentureFoundry\Health\VentureHealthGate;
use App\Services\Ai\VentureFoundry\Reward\ReconciledCashEventStore;

/**
 * Phase 1 — the honest success verdict (composes K1 + K4).
 *
 * Success = sustained recurring revenue, measured ONLY from K1 reconciled
 * settled cash (never self-report) AND gated by K4 multi-signal health. A
 * venture is `succeeded` only when it holds >= threshold settled MRR for >= N
 * consecutive months AND the health gate is green. Sustained-but-unhealthy is
 * `blocked_by_health`, never success. Built NEW (the legacy MRR evaluator is
 * untouched) → byte-identical-OFF.
 */
class VentureReconciledSuccessEvaluator
{
    public const STATUS_INSUFFICIENT = 'insufficient_data';

    public const STATUS_NOT_YET = 'not_yet';

    public const STATUS_SUCCEEDED = 'succeeded';

    public const STATUS_FAILED = 'failed';

    public const STATUS_BLOCKED_BY_HEALTH = 'blocked_by_health';

    public function __construct(
        private readonly ReconciledCashEventStore $cash,
        private readonly VentureHealthGate $health,
    ) {}

    /**
     * @param  array<string,mixed>  $healthSignals  signals for the K4 gate
     * @return array<string,mixed>
     */
    public function evaluate(string $ventureId, array $healthSignals = [], ?int $thresholdCents = null, ?int $minMonths = null): array
    {
        $thresholdCents ??= (int) config('atlas_venture_foundry.success_mrr_threshold_cents', 100000); // R$1.000,00
        $minMonths = max(1, $minMonths ?? (int) config('atlas_venture_foundry.success_min_consecutive_months', 3));

        $monthly = $this->cash->monthlySettledNetCents($ventureId); // Y-m => cents, settled only
        $months = array_keys($monthly);
        $monthsObserved = count($months);

        // Longest run + trailing run of consecutive calendar months >= threshold.
        $best = 0;
        $run = 0;
        foreach ($months as $i => $m) {
            $consecutive = $i > 0 && $this->isNextMonth($months[$i - 1], $m);
            $run = ($monthly[$m] >= $thresholdCents) ? ($consecutive ? $run + 1 : 1) : 0;
            $best = max($best, $run);
        }
        $trailing = $this->trailingRun($monthly, $months, $thresholdCents);

        $mrrStatus = $this->classifyMrr($monthsObserved, $minMonths, $trailing, $best);

        // Compose with K4 health: a sustained-MRR success must also be healthy.
        $healthResult = $this->health->evaluate($healthSignals);
        $status = $mrrStatus;
        if ($mrrStatus === self::STATUS_SUCCEEDED && ! $healthResult['succeeded_eligible']) {
            $status = self::STATUS_BLOCKED_BY_HEALTH;
        }

        return [
            'status' => $status,
            'mrr_status' => $mrrStatus,
            'health_verdict' => $healthResult['verdict'],
            'health_blocking' => ['red' => $healthResult['red'], 'unknown' => $healthResult['unknown']],
            'threshold_cents' => $thresholdCents,
            'min_months' => $minMonths,
            'months_observed' => $monthsObserved,
            'trailing_streak' => $trailing,
            'monthly_cents' => $monthly,
        ];
    }

    private function classifyMrr(int $monthsObserved, int $minMonths, int $trailing, int $best): string
    {
        if ($trailing >= $minMonths) {
            return self::STATUS_SUCCEEDED;
        }
        if ($best >= $minMonths) {
            return self::STATUS_FAILED; // hit the bar, then churned below
        }
        if ($monthsObserved < $minMonths) {
            return self::STATUS_INSUFFICIENT;
        }

        return self::STATUS_NOT_YET;
    }

    /**
     * @param  array<string,int>  $monthly
     * @param  array<int,string>  $months
     */
    private function trailingRun(array $monthly, array $months, int $thresholdCents): int
    {
        $streak = 0;
        for ($i = count($months) - 1; $i >= 0; $i--) {
            if ($monthly[$months[$i]] < $thresholdCents) {
                break;
            }
            if ($i < count($months) - 1 && ! $this->isNextMonth($months[$i], $months[$i + 1])) {
                break;
            }
            $streak++;
        }

        return $streak;
    }

    private function isNextMonth(string $prev, string $next): bool
    {
        return $this->monthIndex($next) - $this->monthIndex($prev) === 1;
    }

    private function monthIndex(string $month): int
    {
        [$y, $m] = array_map('intval', explode('-', $month));

        return $y * 12 + ($m - 1);
    }
}
