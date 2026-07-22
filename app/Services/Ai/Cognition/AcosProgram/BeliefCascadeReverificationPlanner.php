<?php

declare(strict_types=1);

namespace App\Services\Ai\Cognition\AcosProgram;

use App\Services\Ai\Support\AiValueNormalizer;

final class BeliefCascadeReverificationPlanner
{
    public const FIELD_CYCLE_SAFE = 'cycle_safe';
    public const FIELD_ID = 'id';
    public const SCHEMA_VERSION = 'atlas.memory.belief_cascade_reverification.v1';

    public const DEFAULT_DEPTH_CAP = 3;
    public const FIELD_CAPS_HIT = 'caps_hit';
    public const FIELD_CASCADE_ORIGIN = 'cascade_origin';
    public const FIELD_NEEDS_REVERIFICATION = 'needs_reverification';
    public const FIELD_MARKED = 'marked';
    public const FIELD_DEPTH = 'depth';
    public const FIELD_DELETES_DESCENDANTS = 'deletes_descendants';
    public const FIELD_SCHEMA_VERSION = 'schema_version';
    public const FIELD_SOURCE = 'source';
    public const FIELD_SYNC_WRITE_PATH = 'sync_write_path';

    /**
     * @param  array<string,list<string>>  $graph
     * @return array<string,mixed>
     */
    public static function plan(string $origin, array $graph, int $depthCap = self::DEFAULT_DEPTH_CAP): array
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
                $marked[] = [self::FIELD_ID => $child, self::FIELD_NEEDS_REVERIFICATION => true, self::FIELD_CASCADE_ORIGIN => $origin];
                $queue[] = [$child, $depth + 1];
            }
        }

        return [
            self::FIELD_SCHEMA_VERSION => self::SCHEMA_VERSION,
            self::FIELD_MARKED => $marked,
            self::FIELD_CAPS_HIT => [self::FIELD_DEPTH => $depthHit],
            self::FIELD_SOURCE => [
                self::FIELD_DELETES_DESCENDANTS => false,
                self::FIELD_SYNC_WRITE_PATH => false,
                self::FIELD_CYCLE_SAFE => true,
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
