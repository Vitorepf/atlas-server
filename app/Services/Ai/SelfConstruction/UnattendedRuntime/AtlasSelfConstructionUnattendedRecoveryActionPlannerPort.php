<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\UnattendedRuntime;

interface AtlasSelfConstructionUnattendedRecoveryActionPlannerPort
{
    /** @param array<string,mixed> $classification @param array<string,mixed> $snapshot @return array<string,mixed> */
    public function plan(array $classification, array $snapshot = [], array $options = []): array;
}
