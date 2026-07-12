<?php

declare(strict_types=1);

namespace App\Services\Ai\AcosMax;

final class BeliefCascadeReverificationPlanner
{
    public const SCHEMA_VERSION = 'atlas.memory.belief_cascade_reverification.v1';

    /**
     * @param  array<string,list<string>>  $graph
     * @return array<string,mixed>
     */
    public static function plan(string $origin, array $graph, int $depthCap = 3): array
    {
        $queue = [[$origin, 0]];
        $seen = [$origin => true];
        $marked = [];
        $depthHit = false;

        while ($queue !== []) {
            [$node, $depth] = array_shift($queue);
            if ($depth >= $depthCap) {
                if (($graph[$node] ?? []) !== []) {
                    $depthHit = true;
                }
                continue;
            }
            foreach ($graph[$node] ?? [] as $child) {
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
}
