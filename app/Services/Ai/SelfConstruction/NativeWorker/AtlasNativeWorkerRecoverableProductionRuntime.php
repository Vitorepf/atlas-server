<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\NativeWorker;

/** Productive runtime that can resume the daemon's canonical active task lease after restart. */
interface AtlasNativeWorkerRecoverableProductionRuntime extends AtlasNativeWorkerProductionRuntime
{
    /** @return array<string,mixed>|null */
    public function resume(string $clientId): ?array;

    public function renew(string $clientId, string $taskPacketId, string $leaseId): bool;
}
