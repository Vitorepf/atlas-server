<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Quaternity\LoopIntentDrift;

use JsonSerializable;

final class AtlasLoopOperatorIntentDriftFact implements JsonSerializable
{
    public const SCHEMA = 'atlas.loop.operator_intent_drift_fact.v1';

    /**
     * @param  array<string,float>  $axisMagnitudes
     * @param  array<string,int>  $axisDirections
     */
    public function __construct(
        public readonly array $axisMagnitudes,
        public readonly array $axisDirections,
        public readonly float $overallL2Magnitude,
        public readonly int $windowSize,
        public readonly ?string $dominantAxis,
    ) {
    }

    /**
     * @return array{schema:string,axis_magnitudes:array<string,float>,axis_directions:array<string,int>,overall_l2_magnitude:float,window_size:int,dominant_axis:?string}
     */
    public function toArray(): array
    {
        return [
            'schema' => self::SCHEMA,
            'axis_magnitudes' => $this->axisMagnitudes,
            'axis_directions' => $this->axisDirections,
            'overall_l2_magnitude' => $this->overallL2Magnitude,
            'window_size' => $this->windowSize,
            'dominant_axis' => $this->dominantAxis,
        ];
    }

    /**
     * @return array{schema:string,axis_magnitudes:array<string,float>,axis_directions:array<string,int>,overall_l2_magnitude:float,window_size:int,dominant_axis:?string}
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
