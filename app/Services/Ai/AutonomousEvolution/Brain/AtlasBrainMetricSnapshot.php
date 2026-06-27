<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Brain;

use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopScopeComprehensionModel;

/**
 * METRICS-OPTIMIZATION substrate — the portfolio path's lens was "pick the change that most moves a real
 * measured metric, gated by causal effect+CI". The causal selector (S3) had the +CI half wired but no
 * METRIC to optimize: nothing told the brain WHICH measurable thing to push. This organ closes that gap.
 *
 * A METRIC here is a FACT directly derivable from the model + ledger (no provider, no DB, no wall-clock):
 *   - orphan_count          (minimize): how many built-but-unwired organs exist in the scope.
 *   - clone_cluster_count   (minimize): how many duplicated implementations remain.
 *   - doc_stated_gap_count  (minimize): how many gaps the canonical docs themselves call out.
 *   - recent_refusal_count  (minimize): in the last K cycles, how many were a refusal/abstain
 *                                       (the brain's misfire rate — high ⇒ origination is weak).
 *   - recent_served_streak  (maximize): tail-window consecutive `served` rows (compounding signal).
 *
 * Each metric is declared with a TARGET DIRECTION (minimize / maximize) so a downstream chooser
 * (the causal selector + a future "biggest-delta picker") can decide which metric is most under-target
 * and originate against IT. No metric is itself a leverage scalar — they're all COUNTS / TAIL-STREAKS,
 * facts the inspector + ledger expose directly (anti-Goodhart contract again — no scoring).
 *
 * Deterministic, pure. Pétreo: the réu never edits the organ that DECLARES which metrics it should
 * be optimizing — else it'd drop "recent_refusal_count" the moment it became inconvenient (the
 * Goodhart-on-the-meta-objective trap the V4 pétreo block was created to prevent).
 */
final class AtlasBrainMetricSnapshot
{
    public const SCHEMA = 'atlas.brain.metric_snapshot.v1';

    public const DIRECTION_MINIMIZE = 'minimize';

    public const DIRECTION_MAXIMIZE = 'maximize';

    public const DEFAULT_LEDGER_WINDOW = 50;

    /**
     * Compute the current value of every declared metric. Output is byte-stable for the same inputs
     * (no wall-clock, no randomness; the metric_id ordering is the static declaration order).
     *
     * @return array{schema:string, metrics:list<array{id:string, value:int, direction:string, description:string}>}
     */
    public function snapshot(
        AtlasLoopScopeComprehensionModel $model,
        AtlasBrainDoneSetLedger $ledger,
        int $ledgerWindow = self::DEFAULT_LEDGER_WINDOW
    ): array {
        $rows = $ledgerWindow > 0 ? $ledger->recentCycles($ledgerWindow) : [];
        $recentRefusals = 0;
        foreach ($rows as $row) {
            $status = trim((string) ($row['status'] ?? ''));
            if (in_array($status, ['refused', 'abstain', 'already_done', 'prepare_blocked', 'forbidden_target'], true)) {
                $recentRefusals++;
            }
        }
        $streak = 0;
        for ($i = count($rows) - 1; $i >= 0; $i--) {
            $s = trim((string) ($rows[$i]['status'] ?? ''));
            if ($s === 'served' || $s === 'seeded') {
                $streak++;

                continue;
            }
            break;
        }

        return [
            'schema' => self::SCHEMA,
            'metrics' => [
                $this->metric('orphan_count', count((array) $model->orphans), self::DIRECTION_MINIMIZE, 'FQCNs with zero production callers (built-but-unwired organs).'),
                $this->metric('clone_cluster_count', count((array) $model->cloneClusters), self::DIRECTION_MINIMIZE, 'Duplicated implementation clusters detected in the scope.'),
                $this->metric('doc_stated_gap_count', count((array) $model->docStatedGaps), self::DIRECTION_MINIMIZE, 'Gaps the canonical docs themselves call out.'),
                $this->metric('recent_refusal_count', $recentRefusals, self::DIRECTION_MINIMIZE, 'Refusals/abstains in the tail-window (high ⇒ origination is weak).'),
                $this->metric('recent_served_streak', $streak, self::DIRECTION_MAXIMIZE, 'Consecutive served/seeded rows from the tail (compounding signal).'),
            ],
        ];
    }

    /**
     * @return array{id:string, value:int, direction:string, description:string}
     */
    private function metric(string $id, int $value, string $direction, string $description): array
    {
        return [
            'id' => $id,
            'value' => $value,
            'direction' => $direction,
            'description' => $description,
        ];
    }
}
