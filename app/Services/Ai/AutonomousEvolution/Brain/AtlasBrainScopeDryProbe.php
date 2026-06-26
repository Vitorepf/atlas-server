<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Brain;

use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopScopeComprehensionModel;

/**
 * Honest-stop guard — probes whether the loop's scope is genuinely DRY.
 *
 * Returns 'dry' ONLY when the last $m recent cycles ALL refused to originate
 * AND the comprehension model exposes zero new grounded gaps. Otherwise returns
 * 'unknown_blocked'. This prevents the brain harness from fake-declaring the
 * scope dry when there is still grounded work to do.
 *
 * Pure: no provider, no DB, no mutation. Deterministic.
 */
final class AtlasBrainScopeDryProbe
{
    public const SCHEMA_VERSION = 'atlas.brain.scope_dry_probe.v1';

    /**
     * @param  list<array<string,mixed>>  $recentCycles  each cycle from {@see AtlasLoopOriginationPipeline::produce()}
     *                                                   or equivalent — a cycle is a "refusal" when produced=false OR action='abstain' (a produced=true
     *                                                   abstain — park-and-ask — is still a non-origination, so it counts).
     * @param  AtlasLoopScopeComprehensionModel  $model  the current scope comprehension model.
     * @param  int  $m  minimum consecutive refusals required (default 3).
     * @return array{state:string, consecutive_refusals:int, new_grounded_gaps:list<string>}
     */
    public function probe(array $recentCycles, AtlasLoopScopeComprehensionModel $model, int $m = 3): array
    {
        $m = max(1, $m);

        $consecutiveRefusals = $this->countConsecutiveRefusals($recentCycles);
        $newGroundedGaps = $this->groundedGaps($model);

        // 'dry' ONLY when the last $m cycles ALL refused AND zero grounded gaps remain.
        $isDry = $consecutiveRefusals >= $m && $newGroundedGaps === [];

        return [
            'state' => $isDry ? 'dry' : 'unknown_blocked',
            'consecutive_refusals' => $consecutiveRefusals,
            'new_grounded_gaps' => $newGroundedGaps,
        ];
    }

    /**
     * Count consecutive refusals from the END of the recent cycles list.
     * A cycle is a refusal when produced=false or action='abstain'.
     *
     * @param  list<array<string,mixed>>  $cycles
     */
    private function countConsecutiveRefusals(array $cycles): int
    {
        $count = 0;
        for ($i = count($cycles) - 1; $i >= 0; $i--) {
            $cycle = $cycles[$i];
            if (! is_array($cycle)) {
                break;
            }
            $produced = ($cycle['produced'] ?? null);
            $action = (string) ($cycle['action'] ?? '');
            // A cycle is a refusal when nothing was produced OR the action was abstain — REGARDLESS of `produced`.
            // AtlasLoopOriginationPipeline::produce() emits park-and-ask as {produced:true, action:'abstain'}; a
            // produced=true abstain is still a non-origination, so it MUST count toward dry. (Only a produced
            // non-abstain cycle breaks the streak.)
            $isRefusal = ($produced === false) || ($action === 'abstain');
            if (! $isRefusal) {
                break;
            }
            $count++;
        }

        return $count;
    }

    /**
     * Extract grounded gaps from the comprehension model: orphans (built-but-unwired
     * classes) and doc-stated gaps (capabilities the docs name but no symbol provides).
     * These are GROUNDED facts — measured by non-gameable oracles (caller grep,
     * doc analysis) — so their presence means the scope is NOT dry.
     *
     * @return list<string>
     */
    private function groundedGaps(AtlasLoopScopeComprehensionModel $model): array
    {
        return array_values(array_unique(array_merge(
            array_values(array_map('strval', $model->orphans)),
            array_values(array_map('strval', $model->docStatedGaps)),
        )));
    }
}
