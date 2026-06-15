<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Discovery;

/**
 * ABSURD-LEAP 2 — the BLAST-RADIUS / system-understanding brain.
 *
 * To do an "extremely large" obra SAFELY (not blindly), the loop must understand what touching a target
 * IMPACTS across the whole system. This analyzer walks the reverse-dependency graph (who CONSUMES the
 * target, transitively) to compute the blast radius — the set of impacted symbols/files — plus a risk band.
 * The decomposition planner uses it to SCOPE + sequence a big change (touch the leaves before the hubs), and
 * the cross-file-consumer gate uses it to know exactly WHICH consumers must stay green.
 *
 * Pure + deterministic BFS over an INJECTED edge source, bounded by depth + node caps so a hub never
 * explodes the walk. Cycle-safe (visited set).
 *
 * STATUS: keystone only — the real code-graph (AP-811) / reality-graph query and the planner/consumer-gate
 * consumers are the explicit pending integration; this is not wired into a production path yet.
 */
final class AtlasLoopBlastRadiusAnalyzer
{
    /** Absolute danger reference: a target consumed by ~this many symbols is a saturated, high-risk hub. */
    private const HIGH_FAN_OUT = 40.0;

    /**
     * @param  callable(string): list<array{to?:string, node?:string, kind?:string}>  $consumersOf  given a
     *                                                                                              node id, return its DIRECT consumers (reverse-dependency edges)
     * @return array{target:string, blast_radius:list<string>, consumer_count:int, max_depth_reached:int, truncated:bool, risk:string, risk_score:float}
     */
    public function analyze(string $target, callable $consumersOf, int $maxDepth = 3, int $maxNodes = 200): array
    {
        $target = trim($target);
        $maxDepth = max(1, $maxDepth);
        $maxNodes = max(1, $maxNodes);

        $visited = [$target => true];
        $blast = [];
        $frontier = [$target];
        $depthReached = 0;
        $truncated = false;

        for ($depth = 1; $depth <= $maxDepth && $frontier !== []; $depth++) {
            $next = [];
            foreach ($frontier as $node) {
                // The injected edge source is a real query (code-graph / reality-graph) that can fail; a
                // throwing query on one node must not abort the whole analysis — treat it as no edges.
                try {
                    $edges = (array) $consumersOf($node);
                } catch (\Throwable) {
                    $edges = [];
                }
                foreach ($edges as $edge) {
                    $consumer = trim((string) (is_array($edge) ? ($edge['to'] ?? $edge['node'] ?? '') : $edge));
                    if ($consumer === '' || isset($visited[$consumer])) {
                        continue;
                    }
                    if (count($blast) >= $maxNodes) {
                        $truncated = true;
                        break 2;
                    }
                    $visited[$consumer] = true;
                    $blast[] = $consumer;
                    $next[] = $consumer;
                    $depthReached = $depth;
                }
            }
            $frontier = $next;
        }

        $count = count($blast);
        // Risk grows with the SIZE of the impacted set and how DEEP it reaches. The size component is
        // normalized against an ABSOLUTE danger reference (HIGH_FAN_OUT consumers ~= saturated), NOT the
        // analysis cap (maxNodes) — otherwise the same hub would change risk band just by changing the cap.
        $countSeverity = min(1.0, $count / self::HIGH_FAN_OUT);
        $riskScore = round(min(1.0, $countSeverity * 0.7 + ($depthReached / $maxDepth) * 0.3), 4);
        $risk = match (true) {
            $truncated || $riskScore >= 0.75 => 'critical',
            $riskScore >= 0.4 => 'high',
            $riskScore >= 0.15 => 'medium',
            default => 'low',
        };

        return [
            'target' => $target,
            'blast_radius' => $blast,
            'consumer_count' => $count,
            'max_depth_reached' => $depthReached,
            'truncated' => $truncated,
            'risk' => $risk,
            'risk_score' => $riskScore,
        ];
    }
}
