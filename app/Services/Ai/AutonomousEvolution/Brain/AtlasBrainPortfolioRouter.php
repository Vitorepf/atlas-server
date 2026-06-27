<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Brain;

/**
 * PORTFOLIO ROUTER — closes the loop between the structural-signal digest (the brain's perception of
 * non-obvious multi-file leverage) and the 7-path self-improvement portfolio. Given the digest, it
 * recommends the path most aligned with the dominant signal class, so the pasted brain isn't reduced
 * to picking a path uniformly at random when an obvious symptom is staring at it.
 *
 * MAPPING (each path appears EXACTLY once on the LHS — never two paths for one signal class, so the
 * recommendation is deterministic and the rotation stays auditable):
 *   - orphans          → comprehension-deepening   (the path whose lens is "go deeper on a subsystem
 *                                                    to find a non-obvious structural leverage" —
 *                                                    orphans ARE the non-obvious leverage)
 *   - clone_clusters   → pattern-design            (duplicate implementations are exactly the symptom
 *                                                    a pattern-registry match resolves)
 *   - doc_stated_gaps  → frontier-harvest          (a gap the docs themselves call out is a known-
 *                                                    unknown to mine an external frontier technique for)
 *
 * PRIORITY ORDER when multiple signals are present: orphans → clone_clusters → doc_stated_gaps. Orphans
 * win because they're the most certain (a file with zero callers is a fact, not a heuristic). Clones
 * win over docStatedGaps because a duplicate is a concrete code fact vs. an authored claim.
 *
 * EMPTY DIGEST ⇒ null (no recommendation; the brain's normal rotation decides). The router NEVER
 * forces a path — it only surfaces a recommendation when the perception layer has a clear symptom.
 *
 * Author≠judge intact: this routes, it does NOT originate / does NOT gate / does NOT write. Pétreo
 * for the same reason as NextWorkDecider + LeverageSelector: the réu never edits its own priorizador.
 */
final class AtlasBrainPortfolioRouter
{
    public const SCHEMA = 'atlas.brain.portfolio_router.v1';

    public const PATH_COMPREHENSION_DEEPENING = 'comprehension-deepening';

    public const PATH_PATTERN_DESIGN = 'pattern-design';

    public const PATH_FRONTIER_HARVEST = 'frontier-harvest';

    /**
     * @param  array{orphans?:list<string>, clone_clusters?:list<string>, doc_stated_gaps?:list<string>}  $digest
     * @return array{schema:string, recommended_path:?string, reason:string, signal_class:?string}
     */
    public function route(array $digest): array
    {
        if (($digest['orphans'] ?? []) !== []) {
            return $this->result(self::PATH_COMPREHENSION_DEEPENING, 'orphans', 'unwired organs need the deepening lens — they ARE the non-obvious structural leverage');
        }
        if (($digest['clone_clusters'] ?? []) !== []) {
            return $this->result(self::PATH_PATTERN_DESIGN, 'clone_clusters', 'duplicated implementations match the pattern-registry directly');
        }
        if (($digest['doc_stated_gaps'] ?? []) !== []) {
            return $this->result(self::PATH_FRONTIER_HARVEST, 'doc_stated_gaps', 'a documented gap is a known-unknown to mine an external frontier technique for');
        }

        return [
            'schema' => self::SCHEMA,
            'recommended_path' => null,
            'reason' => 'no dominant structural signal — defer to the brain\'s default rotation',
            'signal_class' => null,
        ];
    }

    /**
     * @return array{schema:string, recommended_path:string, reason:string, signal_class:string}
     */
    private function result(string $path, string $signalClass, string $reason): array
    {
        return [
            'schema' => self::SCHEMA,
            'recommended_path' => $path,
            'reason' => $reason,
            'signal_class' => $signalClass,
        ];
    }
}
