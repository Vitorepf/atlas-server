<?php

namespace App\Services\Ai\Telemetry\Engine\Dto;

use App\Models\AiPerformanceRecommendation;

final readonly class RecommendationResult
{
    /**
     * @param  array<int,AiPerformanceRecommendation>  $created
     * @param  array<int,AiPerformanceRecommendation>  $reaffirmed
     * @param  array<int,AiPerformanceRecommendation>  $superseded
     */
    public function __construct(
        public array $created = [],
        public array $reaffirmed = [],
        public array $superseded = [],
        public array $effectivenessScorecard = [],
        public array $meta = [],
    ) {}

    public function totalActive(): int
    {
        return count($this->created) + count($this->reaffirmed);
    }
}
