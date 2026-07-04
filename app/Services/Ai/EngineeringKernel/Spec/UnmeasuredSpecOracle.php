<?php

declare(strict_types=1);

namespace App\Services\Ai\EngineeringKernel\Spec;

/**
 * Engineering Kernel adapter: the honest, fail-closed DEFAULT oracle used until the executional
 * no-op probe (WorkcellSpecOracle over a real WorkcellExecutor) is wired on a given surface.
 *
 * Always reports UNMEASURED so the floor HOLDS — it NEVER fabricates a discriminating pass. This
 * makes AtlasSpecGateAdapter auto-resolvable (like Obra #1's adapter) while staying fail-closed: a
 * surface that has not yet wired a real oracle holds its specs for a witness rather than freezing blind.
 */
final class UnmeasuredSpecOracle implements SpecOracle
{
    public function probe(SpecDraft $draft): OracleReport
    {
        return OracleReport::unmeasured();
    }
}
