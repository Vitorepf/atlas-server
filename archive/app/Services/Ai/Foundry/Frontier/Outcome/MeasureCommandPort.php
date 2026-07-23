<?php

declare(strict_types=1);

namespace App\Services\Ai\Foundry\Frontier\Outcome;

/**
 * Foundry AP-E · real-or-blocked measurement port (I5).
 *
 * Runs the proposal-bound success_metric measure_cmd against the post-merge
 * tree. With no real merged AFEF-origin finding the live binding BLOCKS
 * honestly (ran=false) — it NEVER fabricates a numeric. Tests provide labelled
 * Fake / Blocked impls only; a real implementation shells out the command.
 */
interface MeasureCommandPort
{
    /**
     * @return array{ran:bool,exit_code:int,stdout:string,stderr:string}
     */
    public function run(string $measureCmd, string $property): array;
}
