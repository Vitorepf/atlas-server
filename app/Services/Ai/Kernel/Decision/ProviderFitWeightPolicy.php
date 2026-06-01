<?php

declare(strict_types=1);

namespace App\Services\Ai\Kernel\Decision;

final class ProviderFitWeightPolicy
{
    public const SCHEMA_VERSION = 'atlas.aaeos.provider_fit_weights.v1';

    /** @var list<string> */
    public const RISK_LEVELS = ['critical', 'high', 'medium', 'low'];

    /** @var list<string> */
    public const COMPLEXITIES = ['high', 'medium', 'low'];

    /** @var list<string> */
    public const LANES = ['cheap_fast', 'premium'];

    private const FLOOR = 0.25;

    /**
     * Compute the four fit-dimension weights for one task profile.
     *
     * Raw weights are built additively from a neutral base, then adjusted by
     * complexity, lane and finally risk (risk is applied last so the critical
     * invariant — quality strictly largest, cost strictly smallest — always
     * holds regardless of lane). The four raw weights are normalized by the raw
     * total so the returned dimensions always sum to exactly 1.0.
     *
     * Unknown/empty riskLevel or complexity short-circuits to the neutral
     * profile weights('medium', 'medium', null).
     *
     * @return array{schema_version: string, quality: float, latency: float, cost: float, sample: float}
     */
    public function weights(string $riskLevel, string $complexity, ?string $lane): array
    {
        $risk = $this->normalizeRisk($riskLevel);
        $cx = $this->normalizeComplexity($complexity);

        if ($risk === null || $cx === null) {
            return $this->compose('medium', 'medium', null);
        }

        return $this->compose($risk, $cx, $this->normalizeLane($lane));
    }

    /**
     * @return array{schema_version: string, quality: float, latency: float, cost: float, sample: float}
     */
    private function compose(string $risk, string $complexity, ?string $lane): array
    {
        [$quality, $latency, $cost, $sample] = $this->rawWeights($risk, $complexity, $lane);

        $total = $quality + $latency + $cost + $sample;

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'quality' => $quality / $total,
            'latency' => $latency / $total,
            'cost' => $cost / $total,
            'sample' => $sample / $total,
        ];
    }

    /**
     * @return array{0: float, 1: float, 2: float, 3: float}
     */
    private function rawWeights(string $risk, string $complexity, ?string $lane): array
    {
        $quality = 4.0;
        $latency = 2.0;
        $cost = 2.0;
        $sample = 2.0;

        switch ($complexity) {
            case 'high':
                $quality += 2.0;
                $sample += 1.0;
                break;
            case 'low':
                $latency += 0.5;
                $cost += 0.5;
                break;
        }

        switch ($lane) {
            case 'cheap_fast':
                $latency += 4.0;
                $cost += 4.0;
                $quality -= 2.0;
                $sample -= 1.0;
                break;
            case 'premium':
                $quality += 4.0;
                $sample += 2.0;
                $latency -= 1.0;
                $cost -= 1.0;
                break;
        }

        $quality = $this->floor($quality);
        $latency = $this->floor($latency);
        $cost = $this->floor($cost);
        $sample = $this->floor($sample);

        switch ($risk) {
            case 'critical':
                $quality += 10.0;
                $cost = self::FLOOR;
                $latency = max($latency * 0.5, self::FLOOR + 0.05);
                $sample = max($sample, self::FLOOR + 0.05);
                break;
            case 'high':
                $quality += 4.0;
                $cost = max($cost * 0.5, self::FLOOR);
                break;
            case 'low':
                $quality = $this->floor($quality - 1.0);
                $cost += 1.0;
                $latency += 1.0;
                break;
        }

        return [
            $this->floor($quality),
            $this->floor($latency),
            $this->floor($cost),
            $this->floor($sample),
        ];
    }

    private function floor(float $value): float
    {
        return max($value, self::FLOOR);
    }

    private function normalizeRisk(string $riskLevel): ?string
    {
        $value = strtolower(trim($riskLevel));

        return in_array($value, self::RISK_LEVELS, true) ? $value : null;
    }

    private function normalizeComplexity(string $complexity): ?string
    {
        $value = strtolower(trim($complexity));

        return in_array($value, self::COMPLEXITIES, true) ? $value : null;
    }

    private function normalizeLane(?string $lane): ?string
    {
        if ($lane === null) {
            return null;
        }

        $value = strtolower(trim($lane));

        return in_array($value, self::LANES, true) ? $value : null;
    }
}
