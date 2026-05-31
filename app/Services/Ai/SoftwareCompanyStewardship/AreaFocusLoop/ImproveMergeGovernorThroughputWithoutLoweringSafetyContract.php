<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

interface ImproveMergeGovernorThroughputWithoutLoweringSafetyContract
{
    public function getCurrentThroughput(): int;

    public function getSafetyThreshold(): float;

    public function calculateOptimalBatchSize(int $queueDepth): int;

    public function shouldProceedWithMerge(int $safetyScore): bool;
}