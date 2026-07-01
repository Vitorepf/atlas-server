<?php

declare(strict_types=1);

namespace App\Services\Ai\EngineeringKernel;

/**
 * Engineering Kernel mechanism: run an admitted workcell with scope, budget and tool contract.
 *
 * Owns: executing a workcell that Spec Court already admitted, within its declared scope, budget
 * and tool contract.
 * Must never own: deciding whether a workcell should be admitted in the first place (Spec
 * Court's job), or judging the result afterward (Verification Court's job).
 */
interface WorkcellExecutor
{
    /**
     * @param  array<string,mixed>  $workcell
     * @return array<string,mixed>
     */
    public function execute(array $workcell): array;
}
