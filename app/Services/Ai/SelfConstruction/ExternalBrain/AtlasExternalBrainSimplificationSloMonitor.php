<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * SLO gate for 24/7 autonomous simplification: turns four measurable service-level indicators
 * (green rate, line reduction, proof debt, regression rate) into an operating MODE, so the loop
 * can tell healthy compounding from noisy activity and react accordingly.
 *
 * Missing SLO TARGETS (not metrics — the ceiling/floor configuration itself) can never be silently
 * treated as "healthy": if any of the four targets is absent from input, the monitor cannot safely
 * judge health and falls to repair rather than continuing normal batch creation.
 *
 * MODE PRIORITY (first match wins):
 *   repair — missing SLO target configuration, OR green_rate below target, OR proof_debt above
 *            target: the process itself needs fixing before more batches are created.
 *   stop   — regression_rate above target: regressions are the most severe possible SLO miss and
 *            halt everything, even when other indicators look fine.
 *   accelerate — every SLO is met AND line_reduction exceeds its target by a healthy margin
 *            (>= ACCELERATE_MULTIPLIER): real compounding headroom exists.
 *   steady — every SLO is met but line_reduction isn't comfortably ahead of target.
 *
 * Input shape:
 *   { green_rate?:float, green_rate_target?:float, line_reduction?:int, line_reduction_target?:int,
 *     proof_debt?:int, proof_debt_target?:int, regression_rate?:float, regression_rate_target?:float }
 *
 * Pure: no I/O, no provider calls, no queue mutation.
 */
final class AtlasExternalBrainSimplificationSloMonitor
{
    public const SCHEMA = 'atlas.external_brain.simplification_slo_monitor.v1';

    public const MODE_ACCELERATE = 'accelerate';

    public const MODE_STEADY = 'steady';

    public const MODE_REPAIR = 'repair';

    public const MODE_STOP = 'stop';

    private const ACCELERATE_MULTIPLIER = 1.5;

    private const TARGET_KEYS = [
        'green_rate_target',
        'line_reduction_target',
        'proof_debt_target',
        'regression_rate_target',
    ];

    /**
     * @param  array<string,mixed>  $facts
     * @return array{schema:string, mode:string, reasons:list<string>, slo_checks:array<string,mixed>}
     */
    public function evaluate(array $facts): array
    {
        $missingTargets = array_values(array_filter(
            self::TARGET_KEYS,
            static fn (string $key): bool => ! array_key_exists($key, $facts),
        ));

        if ($missingTargets !== []) {
            return [
                'schema' => self::SCHEMA,
                'mode' => self::MODE_REPAIR,
                'reasons' => ['missing_slo_targets: '.implode(', ', $missingTargets)],
                'slo_checks' => [],
            ];
        }

        $greenRate = (float) ($facts['green_rate'] ?? 0.0);
        $greenRateTarget = (float) $facts['green_rate_target'];
        $lineReduction = (float) ($facts['line_reduction'] ?? 0.0);
        $lineReductionTarget = (float) $facts['line_reduction_target'];
        $proofDebt = (float) ($facts['proof_debt'] ?? 0.0);
        $proofDebtTarget = (float) $facts['proof_debt_target'];
        $regressionRate = (float) ($facts['regression_rate'] ?? 0.0);
        $regressionRateTarget = (float) $facts['regression_rate_target'];

        $greenRateOk = $greenRate >= $greenRateTarget;
        $lineReductionOk = $lineReduction >= $lineReductionTarget;
        $proofDebtOk = $proofDebt <= $proofDebtTarget;
        $regressionOk = $regressionRate <= $regressionRateTarget;

        $sloChecks = [
            'green_rate_ok' => $greenRateOk,
            'line_reduction_ok' => $lineReductionOk,
            'proof_debt_ok' => $proofDebtOk,
            'regression_rate_ok' => $regressionOk,
        ];

        if (! $greenRateOk || ! $proofDebtOk) {
            $reasons = [];
            if (! $greenRateOk) {
                $reasons[] = "green_rate={$greenRate} below target={$greenRateTarget}";
            }
            if (! $proofDebtOk) {
                $reasons[] = "proof_debt={$proofDebt} above target={$proofDebtTarget}";
            }

            return $this->result(self::MODE_REPAIR, $reasons, $sloChecks);
        }

        if (! $regressionOk) {
            return $this->result(self::MODE_STOP, [
                "regression_rate={$regressionRate} above target={$regressionRateTarget}",
            ], $sloChecks);
        }

        if ($lineReductionTarget > 0.0 && $lineReduction >= $lineReductionTarget * self::ACCELERATE_MULTIPLIER) {
            return $this->result(self::MODE_ACCELERATE, [
                "line_reduction={$lineReduction} at or above ".self::ACCELERATE_MULTIPLIER."x target={$lineReductionTarget}",
            ], $sloChecks);
        }

        return $this->result(self::MODE_STEADY, [
            'all SLOs met; line_reduction not yet comfortably ahead of target',
        ], $sloChecks);
    }

    /**
     * @param  list<string>  $reasons
     * @param  array<string,mixed>  $sloChecks
     */
    private function result(string $mode, array $reasons, array $sloChecks): array
    {
        return [
            'schema' => self::SCHEMA,
            'mode' => $mode,
            'reasons' => $reasons,
            'slo_checks' => $sloChecks,
        ];
    }
}
