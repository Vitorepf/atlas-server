<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\Forge;

use App\Services\Ai\EngineeringKernel\EngineeringOutcome;
use App\Services\Ai\EngineeringKernel\ExecutionOrder;

interface ForgeWorkPacketExecutionPort
{
    public function execute(ExecutionOrder $order): EngineeringOutcome;
}
