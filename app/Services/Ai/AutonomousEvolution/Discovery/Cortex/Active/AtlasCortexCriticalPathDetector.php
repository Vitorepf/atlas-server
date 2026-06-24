<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Discovery\Cortex\Active;

/**
 * CORTEX · ACTIVE — the CRITICAL-PATH DETECTOR. Given M named flows (entry-point symbols), it computes each
 * flow's callee closure via {@see AtlasCortexCallGraphProjector} and reports a FACT list of files that appear
 * on the closures of >=K flows. Each fact carries `file_path`, `flow_ids_intersecting`, `intersect_count`, and
 * `shortest_distance_per_flow` — never a single "criticality score", never a ranking.
 *
 * Honest by construction: it INHERITS the projector's depth-truncation blind spots as `UNKNOWN_REGION` facts
 * so a partial / clipped index can never silently hide a critical file. Read-only: the projector DAGs are
 * never mutated.
 */
final class AtlasCortexCriticalPathDetector
{
    public const SCHEMA = 'atlas.cortex.active.critical_path.v1';

    public const FACT_CRITICAL = 'CRITICAL_FILE';

    public const FACT_UNKNOWN_REGION = 'UNKNOWN_REGION';

    public const DEFAULT_K = 2;

    public function __construct(private readonly ?AtlasCortexCallGraphProjector $projector = null) {}

    /**
     * @param  array<string,array{entry:string, depth?:int, reverse?:bool}>  $flows  keyed by flow_id
     * @param  array{callers?:array<string,list<string>>, callees?:array<string,list<string>>, symbols?:array<string,array{file_line?:string,role?:string}>}  $index
     * @return array{schema:string, k:int, flow_ids:list<string>, facts:list<array<string,mixed>>}
     */
    public function detect(array $flows, array $index, int $k = self::DEFAULT_K): array
    {
        $projector = $this->projector ?? new AtlasCortexCallGraphProjector;
        $k = max(1, $k);

        $flowIds = array_keys($flows);
        sort($flowIds, SORT_STRING);

        // file_path => [flow_id => shortest_distance]
        $coverage = [];
        // file_path => fact (unknown region)
        $unknown = [];

        foreach ($flowIds as $flowId) {
            $spec = $flows[$flowId];
            $entry = (string) ($spec['entry'] ?? '');
            $depth = (int) ($spec['depth'] ?? 8);
            $reverse = (bool) ($spec['reverse'] ?? false);
            $dag = $projector->project($entry, $depth, $reverse, $index);

            foreach ((array) $dag['nodes'] as $node) {
                $file = $this->filePathOf((string) ($node['file_line'] ?? ''));
                if ($file === '') {
                    continue;
                }
                $depthAt = (int) ($node['depth'] ?? 0);
                if (! isset($coverage[$file][$flowId]) || $depthAt < $coverage[$file][$flowId]) {
                    $coverage[$file][$flowId] = $depthAt;
                }
            }

            foreach ((array) $dag['truncated'] as $trunc) {
                // The truncation frontier is an UNKNOWN_REGION blind spot: a partial index could hide a
                // critical file beyond the cap, so we surface it instead of silently dropping it.
                $atSymbol = (string) ($trunc['at_symbol'] ?? '');
                $key = $flowId.'@'.$atSymbol;
                if ($atSymbol !== '' && ! isset($unknown[$key])) {
                    $unknown[$key] = [
                        'fact' => self::FACT_UNKNOWN_REGION,
                        'flow_id' => $flowId,
                        'at_symbol' => $atSymbol,
                        'depth_reached' => (int) ($trunc['depth_reached'] ?? 0),
                    ];
                }
            }
        }

        $facts = [];
        foreach ($coverage as $file => $perFlow) {
            $count = count($perFlow);
            if ($count < $k) {
                continue;
            }
            $intersecting = array_keys($perFlow);
            sort($intersecting, SORT_STRING);
            $distances = [];
            foreach ($intersecting as $fid) {
                $distances[$fid] = $perFlow[$fid];
            }
            $facts[] = [
                'fact' => self::FACT_CRITICAL,
                'file_path' => $file,
                'flow_ids_intersecting' => $intersecting,
                'intersect_count' => $count,
                'shortest_distance_per_flow' => $distances,
            ];
        }

        // The detector emits an UNORDERED SET keyed by file_path (no ranking). For determinism in JSON, we
        // sort the array by file_path — but the FACT carries no scalar criticality score.
        usort($facts, static fn (array $a, array $b): int => $a['file_path'] <=> $b['file_path']);

        $unknownList = array_values($unknown);
        usort($unknownList, static fn (array $a, array $b): int => [$a['flow_id'], $a['at_symbol']] <=> [$b['flow_id'], $b['at_symbol']]);

        return [
            'schema' => self::SCHEMA,
            'k' => $k,
            'flow_ids' => $flowIds,
            'facts' => array_merge($facts, $unknownList),
        ];
    }

    private function filePathOf(string $fileLine): string
    {
        $fileLine = trim($fileLine);
        if ($fileLine === '') {
            return '';
        }
        $colon = strrpos($fileLine, ':');

        return $colon === false ? $fileLine : substr($fileLine, 0, $colon);
    }
}
