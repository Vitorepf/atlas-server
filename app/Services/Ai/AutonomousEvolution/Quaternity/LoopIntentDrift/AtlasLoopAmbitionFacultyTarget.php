<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Quaternity\LoopIntentDrift;

use JsonSerializable;

final class AtlasLoopAmbitionFacultyTarget implements JsonSerializable
{
    /**
     * @param  array<string,float>  $axisWeights
     */
    public function __construct(
        public readonly float $ambitionTarget,
        public readonly array $axisWeights,
    ) {
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    public static function fromArray(array $payload): self
    {
        $weights = [];
        $axisWeights = $payload['axis_weights'] ?? [];
        if (is_array($axisWeights)) {
            foreach ($axisWeights as $axis => $weight) {
                if (is_string($axis) && $axis !== '' && is_numeric($weight)) {
                    $weights[$axis] = self::stableFloat((float) $weight);
                }
            }
        }

        return new self(
            ambitionTarget: self::clamp01((float) ($payload['ambition_target'] ?? 0.0)),
            axisWeights: self::sortedWeights($weights),
        );
    }

    /**
     * @return array{ambition_target:float,axis_weights:array<string,float>}
     */
    public function toArray(): array
    {
        return [
            'ambition_target' => self::stableFloat(self::clamp01($this->ambitionTarget)),
            'axis_weights' => self::sortedWeights($this->axisWeights),
        ];
    }

    /**
     * @return array{ambition_target:float,axis_weights:array<string,float>}
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    public function canonicalBytes(): string
    {
        return json_encode($this->toArray(), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    public static function clamp01(float $value): float
    {
        return self::stableFloat(min(1.0, max(0.0, $value)));
    }

    public static function stableFloat(float $value): float
    {
        return round($value, 6);
    }

    /**
     * @param  array<string,float>  $weights
     * @return array<string,float>
     */
    private static function sortedWeights(array $weights): array
    {
        $normalized = [];
        foreach ($weights as $axis => $weight) {
            if (is_string($axis) && $axis !== '') {
                $normalized[$axis] = self::stableFloat((float) $weight);
            }
        }

        ksort($normalized, SORT_STRING);

        return $normalized;
    }
}
