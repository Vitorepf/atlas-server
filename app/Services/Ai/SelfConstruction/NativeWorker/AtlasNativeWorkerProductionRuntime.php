<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\NativeWorker;

/** Typed bridge to the existing canonical serving and materialization owners. */
interface AtlasNativeWorkerProductionRuntime
{
    /** @return array<string,mixed>|null */
    public function claim(string $clientId): ?array;

    /** @param array<string,mixed> $outcome @return array<string,mixed> */
    public function report(string $clientId, array $outcome): array;

    /** @param array<string,mixed> $patchPlan @return array<string,mixed> */
    public function materialize(array $patchPlan): array;
}
