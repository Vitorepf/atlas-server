<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\UnattendedRuntime;

interface AtlasSelfConstructionUnattendedStallClassifierPort
{
    /** @param array<string,mixed> $snapshot @return array<string,mixed> */
    public function classify(array $snapshot): array;

    /** @param array<string,mixed> $snapshot @return array<string,mixed> */
    public function classifyStallAction(array $snapshot): array;
}
