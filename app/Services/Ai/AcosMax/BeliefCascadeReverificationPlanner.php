<?php

declare(strict_types=1);

namespace App\Services\Ai\AcosMax;

use App\Services\Ai\Support\AiValueNormalizer;

final class BeliefCascadeReverificationPlanner
{
    public const SCHEMA_VERSION = 'atlas.memory.belief_cascade_reverification.v1';

    /**
     * @param  array<string,list<string>>  $graph
     * @return array<string,mixed>
     */
    public static function plan(string $origin, array $graph, int $depthCap = 3): array
    {
        $origin = AiValueNormalizer::trimmedStringOrNull($origin) ?? '';
        $normalizedGraph = self::normalizeGraph($graph);

        $queue = [[$origin, 0]];
        $seen = [$origin => true];
        $marked = [];
        $depthHit = false;

        while ($queue !== []) {
            [$node, $depth] = array_shift($queue);
            if ($depth >= $depthCap) {
                if (($normalizedGraph[$node] ?? []) !== []) {
                    $depthHit = true;
                }
                continue;
            }
            foreach ($normalizedGraph[$node] ?? [] as $child) {
                if (isset($seen[$child])) {
                    continue;
                }
                $seen[$child] = true;
                $marked[] = ['id' => $child, 'needs_reverification' => true, 'cascade_origin' => $origin];
                $queue[] = [$child, $depth + 1];
            }
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'marked' => $marked,
            'caps_hit' => ['depth' => $depthHit],
            'source' => [
                'deletes_descendants' => false,
                'sync_write_path' => false,
                'cycle_safe' => true,
            ],
        ];
    }

    /**
     * @param  array<string,list<string>>  $graph
     * @return array<string,list<string>>
     */
    private static function normalizeGraph(array $graph): array
    {
        $normalized = [];
        foreach ($graph as $node => $children) {
            $nodeKey = AiValueNormalizer::trimmedStringOrNull($node);
            if ($nodeKey === null) {
                continue;
            }
            $seenChildren = [];
            $cleanChildren = [];
            foreach (AiValueNormalizer::arrayOrEmpty($children) as $child) {
                $childId = AiValueNormalizer::trimmedStringOrNull($child);
                if ($childId === null || isset($seenChildren[$childId])) {
                    continue;
                }
                $seenChildren[$childId] = true;
                $cleanChildren[] = $childId;
            }
            $normalized[$nodeKey] = $cleanChildren;
        }

        return $normalized;
    }
}
