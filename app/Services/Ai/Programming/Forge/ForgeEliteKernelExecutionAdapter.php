<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\Forge;

use App\Services\Ai\EngineeringKernel\EliteExecutorKernel;
use App\Services\Ai\EngineeringKernel\EngineeringOutcome;
use App\Services\Ai\EngineeringKernel\ExecutionOrder;

final readonly class ForgeEliteKernelExecutionAdapter implements ForgeWorkPacketExecutionPort
{
    public function __construct(private EliteExecutorKernel $kernel) {}

    public function execute(ExecutionOrder $order): EngineeringOutcome
    {
        return $this->kernel->execute($order);
    }
}
