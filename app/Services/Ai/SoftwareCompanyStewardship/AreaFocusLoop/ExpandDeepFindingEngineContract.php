<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

interface ExpandDeepFindingEngineContract
{
    public function expand(string $findingId): array;
}