<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

/**
 * Pure utilization/quality gate for the autonomous loop (AP-790).
 *
 * It scores a completed run by how many of its cycles were genuinely useful
 * (consumed by runtime) versus inert (e.g. a new class+test delivered with no
 * runtime consumption). When the honest projected useful rate falls below the
 * 0.96 floor the run is paused for 24h with an honest blocker, so the loop
 * never keeps burning provider spend to prove its own failure.
 *
 * Pure: every field is computed from the $run argument. No I/O, clock, random.
 */
final class LoopUtilizationQualityGovernor
{
    private const SCHEMA_VERSION = 'atlas.loop.utilization_quality_governor.v1';

    /**
     * Minimum honest useful-cycle rate required to admit AP-790.
     */
    private const USEFUL_CYCLE_RATE_FLOOR = 0.96;

    /**
     * Wall-clock pause applied when the run is below the utilization floor.
     */
    private const PAUSE_HOURS = 24;

    /**
     * Honest blocker emitted when the projected useful rate is below the floor.
     */
    private const LOW_UTILIZATION_BLOCKER = 'projected_useful_rate_below_floor';

    /**
     * Delivery kind that is treated as inert when it is not consumed by runtime.
     */
    private const INERT_DELIVERY_KIND = 'class_plus_test';

    /**
     * @param  array{cycles?: list<array<string, mixed>>}  $run
     * @return array{
     *     schema_version: string,
     *     total_cycles: int,
     *     useful_cycles: int,
     *     useful_cycle_rate: float,
     *     projected_useful_rate: float,
     *     provider_spend_wasted_rate: float,
     *     inert_delivery_count: int,
     *     paused_for_low_utilization: bool,
     *     pause_hours: int,
     *     gate_decision: string,
     *     ap_790_admitted: bool,
     *     blockers: list<string>
     * }
     */
    public function evaluate(array $run): array
    {
        $cycles = $this->cycles($run);
        $totalCycles = count($cycles);

        $reportedUsefulCycles = 0;
        $genuinelyUsefulCycles = 0;
        $inertDeliveryCount = 0;
        $totalSpend = 0.0;
        $wastedSpend = 0.0;

        foreach ($cycles as $cycle) {
            $runtimeConsumed = $this->payloadBool($cycle, 'runtime_consumed');
            $reportedUseful = $this->reportedUseful($cycle, $runtimeConsumed);
            $spend = $this->spend($cycle);

            if ($reportedUseful) {
                $reportedUsefulCycles++;
            }

            if ($runtimeConsumed) {
                $genuinelyUsefulCycles++;
            } else {
                $wastedSpend += $spend;
            }

            if ($this->isInertDelivery($cycle, $runtimeConsumed)) {
                $inertDeliveryCount++;
            }

            $totalSpend += $spend;
        }

        $usefulCycleRate = $this->ratio($reportedUsefulCycles, $totalCycles);
        $projectedUsefulRate = $this->ratio($genuinelyUsefulCycles, $totalCycles);
        $providerSpendWastedRate = $this->ratio($wastedSpend, $totalSpend);

        $belowFloor = $totalCycles > 0 && $projectedUsefulRate < self::USEFUL_CYCLE_RATE_FLOOR;

        $blockers = [];
        if ($belowFloor) {
            $blockers[] = self::LOW_UTILIZATION_BLOCKER;
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'total_cycles' => $totalCycles,
            'useful_cycles' => $genuinelyUsefulCycles,
            'useful_cycle_rate' => $usefulCycleRate,
            'projected_useful_rate' => $projectedUsefulRate,
            'provider_spend_wasted_rate' => $providerSpendWastedRate,
            'inert_delivery_count' => $inertDeliveryCount,
            'paused_for_low_utilization' => $belowFloor,
            'pause_hours' => $belowFloor ? self::PAUSE_HOURS : 0,
            'gate_decision' => $belowFloor ? 'block' : 'pass',
            'ap_790_admitted' => ! $belowFloor && $totalCycles > 0,
            'blockers' => $blockers,
        ];
    }

    /**
     * @param  array{cycles?: list<array<string, mixed>>}  $run
     * @return list<array<string, mixed>>
     */
    private function cycles(array $run): array
    {
        $cycles = $run['cycles'] ?? [];

        if (! is_array($cycles)) {
            return [];
        }

        $normalised = [];
        foreach ($cycles as $cycle) {
            if (is_array($cycle)) {
                $normalised[] = $cycle;
            }
        }

        return $normalised;
    }

    /**
     * A delivery is inert when it ships a class+test pair that runtime never
     * consumed; such cycles add no real utilization to the loop.
     *
     * @param  array<string, mixed>  $cycle
     */
    private function isInertDelivery(array $cycle, bool $runtimeConsumed): bool
    {
        if ($runtimeConsumed) {
            return false;
        }

        return strtolower(trim($this->payloadRawString($cycle, 'delivery_kind'))) === self::INERT_DELIVERY_KIND;
    }

    /**
     * Reported usefulness defaults to runtime consumption so honest runs need no
     * extra flag; an optimistic self-report is only honoured for the observed
     * rate, never for the projected gate.
     *
     * @param  array<string, mixed>  $cycle
     */
    private function reportedUseful(array $cycle, bool $runtimeConsumed): bool
    {
        if (! array_key_exists('reported_useful', $cycle)) {
            return $runtimeConsumed;
        }

        return $this->payloadBool($cycle, 'reported_useful');
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function payloadBool(array $payload, string $key): bool
    {
        $value = $payload[$key] ?? false;

        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return $value !== 0 && $value !== 0.0;
        }

        if (! is_string($value)) {
            return false;
        }

        return match (strtolower(trim($value))) {
            '1', 'true', 'yes', 'on' => true,
            default => false,
        };
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function payloadRawString(array $payload, string $key): string
    {
        $value = $payload[$key] ?? '';

        if (is_string($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value) || is_bool($value)) {
            return (string) $value;
        }

        return '';
    }

    /**
     * @param  array<string, mixed>  $cycle
     */
    private function spend(array $cycle): float
    {
        $value = $cycle['provider_spend'] ?? 0;

        if (is_int($value) || is_float($value)) {
            return $this->sanitiseSpend((float) $value);
        }

        if (is_string($value) && is_numeric($value)) {
            return $this->sanitiseSpend((float) $value);
        }

        return 0.0;
    }

    /**
     * Provider spend is clamped to a finite, non-negative float. A non-finite
     * value (INF/NAN, or a numeric string such as "1e400" that overflows to INF)
     * is malformed input and takes the safe 0.0 path so it can never poison the
     * wasted-spend rate with a non-finite (NAN/INF) result.
     */
    private function sanitiseSpend(float $value): float
    {
        if (! is_finite($value)) {
            return 0.0;
        }

        return max(0.0, $value);
    }

    private function ratio(float|int $numerator, float|int $denominator): float
    {
        if ($denominator <= 0) {
            return 0.0;
        }

        $rate = (float) $numerator / (float) $denominator;

        // A non-finite rate (NAN/INF) must never escape: it would break the
        // 0..1 bound, poison JSON serialisation and silently fail-open in any
        // downstream "< floor" comparison. Treat it as the safe 0.0 floor.
        if (! is_finite($rate) || $rate < 0.0) {
            return 0.0;
        }

        if ($rate > 1.0) {
            return 1.0;
        }

        return $rate;
    }
}
