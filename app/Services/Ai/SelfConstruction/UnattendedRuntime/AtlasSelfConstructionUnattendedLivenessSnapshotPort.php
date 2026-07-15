<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\UnattendedRuntime;

interface AtlasSelfConstructionUnattendedLivenessSnapshotPort
{
    /** @param array<string,mixed> $facts @return array<string,mixed> */
    public function compose(array $facts): array;
}
