<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\AtlasDev\Execution;

use App\Services\Ai\EngineeringKernel\EngineeringOutcome;

interface DevKernelExecutionPort
{
    public function execute(ConfirmedDevRun $run, DevPlan $plan): EngineeringOutcome;
}
