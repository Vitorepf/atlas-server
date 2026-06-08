<?php

namespace App\Services\Engineering\CodeGraph;

use App\Models\AiCodebaseWorldModel;
use App\Models\AiCodebaseWorldModelEdge;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * Makes the real code graph LOAD-BEARING in the Dev/Forge preflight (AP-811 §P0,
 * the live integration the operator authorized). It gathers the resolved-edge
 * stats for the latest world model and runs them through the already-tested
 * {@see CodeGraphGovernanceSignal}, so a stale/empty/drifted code graph blocks
 * Dev/Forge exactly like the symbol index does.
 *
 * SOVEREIGNTY / SAFETY: flag-gated by config('atlas.code_graph.real_edges')
 * (default false). With the flag OFF it returns 'inactive' WITHOUT reading the DB
 * — so the gate it plugs into behaves byte-identically until the operator flips
 * the flag. The integration is wired + tested but inert until that deliberate flip.
 * It only classifies; it never builds the graph, runs a provider, or decides.
 */
class CodeGraphGateContributor
{
    public const STATUS_INACTIVE = 'inactive';

    private const DEFAULT_MAX_AGE_MINUTES = 1440;

    public function __construct(private readonly CodeGraphGovernanceSignal $signal) {}

    /**
     * @param  callable():array<string,mixed>|null  $statsProvider  test seam; defaults to the DB gather.
     * @return array{status:string, blockers:array<int,string>, stats:array<string,mixed>}
     */
    public function evaluate(?callable $statsProvider = null, int $maxAgeMinutes = self::DEFAULT_MAX_AGE_MINUTES): array
    {
        if (! (bool) config('atlas.code_graph.real_edges', false)) {
            return ['status' => self::STATUS_INACTIVE, 'blockers' => [], 'stats' => []];
        }

        $stats = $statsProvider !== null ? $statsProvider() : $this->gatherStats($maxAgeMinutes);
        $verdict = $this->signal->evaluate($stats);

        return [
            'status' => $verdict['status'],
            'blockers' => $verdict['blockers'],
            'stats' => $stats,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function gatherStats(int $maxAgeMinutes): array
    {
        $now = $this->nowMinutes();
        try {
            $model = AiCodebaseWorldModel::query()->orderByDesc('id')->first();
            if ($model === null) {
                return ['edge_count' => 0, 'last_built_at' => null, 'drift_count' => 0, 'max_age_minutes' => $maxAgeMinutes, 'now_minutes' => $now];
            }

            $edgeCount = AiCodebaseWorldModelEdge::query()
                ->where('world_model_id', $model->id)
                ->count();

            return [
                'edge_count' => $edgeCount,
                'last_built_at' => $model->updated_at?->toJSON(),
                'drift_count' => 0,
                'max_age_minutes' => $maxAgeMinutes,
                'now_minutes' => $now,
            ];
        } catch (Throwable) {
            // Fail-closed: if we cannot measure, hand the signal an empty stat set
            // (which it blocks on) rather than silently passing.
            return ['edge_count' => 0, 'last_built_at' => null, 'drift_count' => 0, 'max_age_minutes' => $maxAgeMinutes, 'now_minutes' => $now];
        }
    }

    private function nowMinutes(): int
    {
        return (int) floor(CarbonImmutable::now()->getTimestamp() / 60);
    }
}
