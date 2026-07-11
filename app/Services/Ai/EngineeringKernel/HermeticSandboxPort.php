<?php

declare(strict_types=1);

namespace App\Services\Ai\EngineeringKernel;

/**
 * Kernel port for applying a verified candidate inside an isolated sandbox.
 * The concrete native implementation remains the only default adapter.
 */
interface HermeticSandboxPort
{
    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function execute(array $input): array;
}
