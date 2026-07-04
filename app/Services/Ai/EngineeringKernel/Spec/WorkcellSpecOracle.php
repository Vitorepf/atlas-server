<?php

declare(strict_types=1);

namespace App\Services\Ai\EngineeringKernel\Spec;

use App\Services\Ai\EngineeringKernel\WorkcellExecutor;
use Throwable;

/**
 * Engineering Kernel adapter: the REAL, provider-free oracle. It asks a WorkcellExecutor to run each
 * behavioral criterion's frozen verification_ref against a NO-OP (do-nothing) implementation, and
 * reports which ones went genuinely RED. This is the only honest oracle-adequacy signal — it needs a
 * test runner, never a provider.
 *
 * Owns: building the no-op probe workcell and interpreting its result into an OracleReport.
 * Must never own: the acceptance decision (SovereignSpecFloor). If the probe cannot run, it reports
 * UNMEASURED (=> the floor HOLDS) — it never fabricates a discriminating pass.
 *
 * Workcell contract: send {kind:'spec_noop_probe', probes:[{id, command}]}; receive
 * {ran:bool, red_ids:[criteria ids that FAILED against the no-op]}.
 */
final class WorkcellSpecOracle implements SpecOracle
{
    public function __construct(
        private readonly WorkcellExecutor $executor,
    ) {}

    public function probe(SpecDraft $draft): OracleReport
    {
        $probes = [];
        foreach ($draft->behavioralCriteria() as $ac) {
            $command = trim((string) ($ac['verification_ref'] ?? ''));
            if ($command === '') {
                continue; // a criterion with no runnable ref cannot be probed; the floor flags it as vacuous
            }
            $probes[] = ['id' => (string) ($ac['id'] ?? ''), 'command' => $command];
        }

        if ($probes === []) {
            return OracleReport::unmeasured();
        }

        try {
            $result = $this->executor->execute([
                'kind' => 'spec_noop_probe',
                'probes' => $probes,
            ]);
        } catch (Throwable) {
            return OracleReport::unmeasured();
        }

        if (($result['ran'] ?? false) !== true) {
            return OracleReport::unmeasured();
        }

        return OracleReport::executional(array_values(array_map('strval', (array) ($result['red_ids'] ?? []))));
    }
}
