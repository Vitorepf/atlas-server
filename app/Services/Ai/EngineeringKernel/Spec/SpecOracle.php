<?php

declare(strict_types=1);

namespace App\Services\Ai\EngineeringKernel\Spec;

/**
 * Engineering Kernel port: probe a spec's frozen tests against a NO-OP wrong implementation.
 *
 * Owns: the seam through which the (impure) execution of the frozen verification refs against a
 * do-nothing stub is obtained, so the SovereignSpecFloor stays a pure value-in/verdict-out floor.
 * Must never own: deciding the verdict. It only reports which criteria genuinely went RED.
 */
interface SpecOracle
{
    public function probe(SpecDraft $draft): OracleReport;
}
