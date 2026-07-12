<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\AtlasDev\Execution;

interface DevPlanRunFacade
{
    public function plan(DevIntent $intent): DevPlan;

    public function run(ConfirmedDevRun $run, ?DevPlan $planned = null): DevRunResult;
}
