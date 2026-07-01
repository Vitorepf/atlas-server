<?php

declare(strict_types=1);

namespace App\Services\Ai\EngineeringKernel;

/**
 * Engineering Kernel mechanism: measure token, time, cost, retries and waste.
 *
 * Owns: measuring and summarizing resource usage — tokens, time, cost, retries, waste.
 * Must never own: budget POLICY (what limit applies for a given risk level, project or autonomy
 * level) — that is data owned by the Policy Plane; this mechanism only measures against it.
 */
interface BudgetMeter
{
    /**
     * @param  array<string,mixed>  $usage
     * @return array<string,mixed>
     */
    public function measure(array $usage): array;

    /**
     * @param  array<string,mixed>  $filters
     * @return array<string,mixed>
     */
    public function summarize(array $filters = []): array;
}
