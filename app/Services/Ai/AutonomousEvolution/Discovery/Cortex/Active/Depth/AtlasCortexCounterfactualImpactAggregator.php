<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Discovery\Cortex\Active\Depth;

/**
 * FACT-only co-location aggregator for hypothetical-walk impacts.
 *
 * Consumes a set of WalkResult arrays (from {@see AtlasCortexCounterfactualMultiStepWalker}) and
 * emits an immutable list of ImpactCluster entries: which sites are touched by more than one walk,
 * which walks contribute, and how many distinct mutation_kinds touched the site.
 *
 * NEVER ranks. NEVER recommends. NEVER assigns a scalar score. Pure co-occurrence counting.
 * Flag-off ⇒ byte-identical no-op (empty list).
 *
 * Site equivalence is computed by the canonical overlap predicate ({@see canonicalSite}) —
 * lowercase + trim + collapse leading slash — chosen to match the central MF-12 chokepoint.
 */
final class AtlasCortexCounterfactualImpactAggregator
{
    public const SCHEMA = 'atlas.cortex.counterfactual_impact_aggregator.v1';

    public function __construct(private readonly bool $enabled)
    {
    }

    /**
     * @param  list<array{walk_id?:string, steps:list<array<string,mixed>>}>  $walks
     * @return array<string,mixed>
     */
    public function aggregate(array $walks): array
    {
        if (! $this->enabled) {
            return [
                'schema_version' => self::SCHEMA,
                'clusters' => [],
                'reason' => 'flag_off',
            ];
        }

        $bySite = [];
        foreach ($walks as $walkIndex => $walk) {
            $walkId = (string) ($walk['walk_id'] ?? 'walk-'.$walkIndex);
            $steps = is_array($walk['steps'] ?? null) ? $walk['steps'] : [];
            foreach ($steps as $step) {
                if (! is_array($step)) {
                    continue;
                }
                $site = (string) ($step['site'] ?? '');
                if ($site === '') {
                    continue;
                }
                $canonical = $this->canonicalSite($site);
                $bySite[$canonical] ??= ['site_id' => $canonical, 'walks' => [], 'kinds' => []];
                $bySite[$canonical]['walks'][$walkId] = true;
                $kind = (string) ($step['mutation_kind'] ?? '');
                if ($kind !== '') {
                    $bySite[$canonical]['kinds'][$kind] = true;
                }
            }
        }

        $clusters = [];
        foreach ($bySite as $site => $bucket) {
            if (count($bucket['walks']) < 2) {
                continue;
            }
            $walkIds = array_keys($bucket['walks']);
            sort($walkIds, SORT_STRING);
            $clusters[] = [
                'site_id' => $site,
                'contributing_walk_ids' => $walkIds,
                'mutation_kind_count' => count($bucket['kinds']),
            ];
        }
        usort($clusters, static fn (array $a, array $b): int => strcmp($a['site_id'], $b['site_id']));

        return [
            'schema_version' => self::SCHEMA,
            'clusters' => $clusters,
        ];
    }

    private function canonicalSite(string $site): string
    {
        $trim = ltrim(trim($site), '/');

        return strtolower($trim);
    }
}
