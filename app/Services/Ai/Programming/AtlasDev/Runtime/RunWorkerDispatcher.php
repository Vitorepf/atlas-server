<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\AtlasDev\Runtime;

interface RunWorkerDispatcher
{
    public function dispatch(string $runId, string $taskContractHash, ?string $expectedCompactSddHash = null): ?int;
}
