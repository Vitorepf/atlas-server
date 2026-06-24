<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Discovery\Cortex\Insights;

interface AtlasCortexInsightObserverContract
{
    /**
     * @param  array<string,mixed>  $facts
     * @return array<string,mixed>
     */
    public function observe(array $facts): array;
}
