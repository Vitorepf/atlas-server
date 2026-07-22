<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Quaternity\LoopIntentDrift;

use Throwable;

final class AtlasLoopOperatorIntentDriftDetector
{
    private const DEFAULT_WINDOW_MAX = 12;
    private const DECIMAL_PLACES = 6;

    /**
     * @param  list<array<string,mixed>>  $intents
     */
    public function detect(array $intents): AtlasLoopOperatorIntentDriftFact
    {
        $window = array_slice(array_values($intents), -$this->windowMax());
        $windowSize = count($window);

        if ($windowSize === 0) {
            return new AtlasLoopOperatorIntentDriftFact([], [], 0.0, 0, null);
        }

        $latest = $this->axisValues($window[$windowSize - 1]);
        $axisNames = $this->axisNames($window, $latest);
        $magnitudes = [];
        $directions = [];
        $sumSquares = 0.0;

        foreach ($axisNames as $axis) {
            $latestValue = $latest[$axis] ?? 0.0;
            $mean = $this->meanForAxis($window, $axis);
            $delta = $latestValue - $mean;
            $magnitude = $this->stableFloat(abs($delta));

            $magnitudes[$axis] = $magnitude;
            $directions[$axis] = $delta > 0.0 ? 1 : ($delta < 0.0 ? -1 : 0);
            $sumSquares += $magnitude * $magnitude;
        }

        return new AtlasLoopOperatorIntentDriftFact(
            axisMagnitudes: $magnitudes,
            axisDirections: $directions,
            overallL2Magnitude: $this->stableFloat(sqrt($sumSquares)),
            windowSize: $windowSize,
            dominantAxis: $this->dominantAxis($magnitudes),
        );
    }

    private function windowMax(): int
    {
        $configured = self::DEFAULT_WINDOW_MAX;

        if (function_exists('config')) {
            try {
                $configured = (int) config('atlas.loop.quaternity.intent_drift.window_max', self::DEFAULT_WINDOW_MAX);
            } catch (Throwable) {
                $configured = self::DEFAULT_WINDOW_MAX;
            }
        }

        return max(1, $configured);
    }

    /**
     * @param  array<string,mixed>  $intent
     * @return array<string,float>
     */
    private function axisValues(array $intent): array
    {
        $values = [];
        $ambitionTarget = $intent['ambition_target'] ?? null;
        if (is_numeric($ambitionTarget)) {
            $values['ambition_target'] = (float) $ambitionTarget;
        }

        $axes = $intent['axes'] ?? [];
        if (is_array($axes)) {
            foreach ($axes as $name => $value) {
                if (is_string($name) && $name !== '' && is_numeric($value)) {
                    $values[$name] = (float) $value;
                }
            }
        }

        ksort($values, SORT_STRING);

        return $values;
    }

    /**
     * @param  list<array<string,mixed>>  $window
     * @param  array<string,float>  $latest
     * @return list<string>
     */
    private function axisNames(array $window, array $latest): array
    {
        $names = array_fill_keys(array_keys($latest), true);

        foreach ($window as $intent) {
            foreach (array_keys($this->axisValues($intent)) as $axis) {
                $names[$axis] = true;
            }
        }

        $axisNames = array_keys($names);
        sort($axisNames, SORT_STRING);

        return $axisNames;
    }

    /**
     * @param  list<array<string,mixed>>  $window
     */
    private function meanForAxis(array $window, string $axis): float
    {
        $sum = 0.0;

        foreach ($window as $intent) {
            $sum += $this->axisValues($intent)[$axis] ?? 0.0;
        }

        return $sum / max(1, count($window));
    }

    /**
     * @param  array<string,float>  $magnitudes
     */
    private function dominantAxis(array $magnitudes): ?string
    {
        $dominant = null;
        $dominantMagnitude = -1.0;

        foreach ($magnitudes as $axis => $magnitude) {
            if ($magnitude > $dominantMagnitude) {
                $dominant = $axis;
                $dominantMagnitude = $magnitude;
            }
        }

        return $dominant;
    }

    private function stableFloat(float $value): float
    {
        return round($value, self::DECIMAL_PLACES);
    }
}
