<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\RepoWide;

/**
 * REPO-WIDE COMPREHENSION MODEL (R8.0) — federates the per-scope comprehension facts produced by
 * AtlasLoopScopeComprehensionModelBuilder into ONE repo-wide read-model. It unions symbols/orphans/clones
 * across every scope (deduped by fqcn / cluster id) and performs CROSS-DOMAIN CALLER RESOLUTION: a class that
 * is orphan within its OWN scope (the scope-local grep saw no caller) but is referenced as a caller-target in
 * ANOTHER scope is NOT a real orphan, so it is removed from the federated orphan set.
 *
 * "Called anywhere" is the union of: each scope's explicit caller_targets list, plus any inventory symbol a
 * scope measured as non-orphan (is_orphan=false ⇒ it has callers there). Pure + deterministic — same inputs
 * yield a byte-identical model and a stable model_hash (sha256 of the canonical, key-sorted model). No I/O.
 */
final class AtlasLoopRepoWideComprehensionModel
{
    public const SCHEMA = 'atlas.loop.repo_wide_comprehension.v1';

    /**
     * @param  list<array<string,mixed>>  $perScopeModels  each the array shape from AtlasLoopScopeComprehensionModelBuilder
     * @return array<string,mixed>
     */
    public function build(array $perScopeModels): array
    {
        $symbols = [];   // fqcn => symbol (deduped, first wins)
        $orphanSet = []; // fqcn => true
        $calledSet = []; // fqcn => true (caller-targets across all scopes)
        $clones = [];    // cluster key => cluster
        $scopeCount = 0;

        foreach ($perScopeModels as $scope) {
            if (! is_array($scope)) {
                continue;
            }
            $scopeCount++;

            foreach ((array) ($scope['inventory'] ?? $scope['symbols'] ?? []) as $item) {
                if (! is_array($item)) {
                    continue;
                }
                $fqcn = trim((string) ($item['fqcn'] ?? ''));
                if ($fqcn === '') {
                    continue;
                }
                $symbols[$fqcn] ??= $item;
                if (($item['is_orphan'] ?? null) === false) {
                    $calledSet[$fqcn] = true; // measured as having callers in this scope
                }
            }

            foreach ((array) ($scope['orphans'] ?? []) as $orphan) {
                $fqcn = trim((string) (is_array($orphan) ? ($orphan['fqcn'] ?? '') : $orphan));
                if ($fqcn !== '') {
                    $orphanSet[$fqcn] = true;
                }
            }

            foreach ((array) ($scope['caller_targets'] ?? []) as $target) {
                $fqcn = trim((string) (is_array($target) ? ($target['fqcn'] ?? '') : $target));
                if ($fqcn !== '') {
                    $calledSet[$fqcn] = true;
                }
            }

            foreach ((array) ($scope['clone_clusters'] ?? $scope['cloneClusters'] ?? $scope['clones'] ?? []) as $cluster) {
                if (! is_array($cluster)) {
                    continue;
                }
                $key = trim((string) ($cluster['cluster_id'] ?? ''));
                if ($key === '') {
                    $key = hash('sha256', (string) json_encode($this->canonicalize($cluster), JSON_UNESCAPED_SLASHES));
                }
                $clones[$key] ??= $cluster;
            }
        }

        // Cross-domain caller resolution: a federated orphan must be called by NOBODY across the whole repo.
        $orphans = array_values(array_filter(
            array_keys($orphanSet),
            static fn (string $fqcn): bool => ! isset($calledSet[$fqcn]),
        ));
        sort($orphans);

        ksort($symbols);
        $symbolList = array_values($symbols);
        ksort($clones);
        $cloneList = array_values($clones);

        $model = [
            'schema' => self::SCHEMA,
            'symbols' => $symbolList,
            'orphans' => $orphans,
            'clones' => $cloneList,
            'scope_count' => $scopeCount,
            'symbol_count' => count($symbolList),
        ];
        $model['model_hash'] = hash('sha256', (string) json_encode($this->canonicalize($model), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return $model;
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        $isList = array_is_list($value);
        $out = [];
        foreach ($value as $k => $v) {
            $out[$k] = $this->canonicalize($v);
        }
        if (! $isList) {
            ksort($out);
        }

        return $out;
    }
}
