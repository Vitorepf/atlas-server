<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Discovery\Supply;

use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopScopeComprehensionModel;

/**
 * Cross-leverage supply lane.
 *
 * ANTI-GOODHART: this lane does not mint generic "refactor plus wiring" bundles. It only emits a spec when
 * the comprehension model proves a material pair: an orphaned capability and a duplicate clone cluster live
 * in the same root subdirectory. That means one architectural decision can both unify the duplicate structure
 * and wire the previously-unused capability into the remaining caller path. No provider calls, no filesystem
 * reads, no ranking proxy.
 */
final class CrossLeverageSupplyLane implements SupplyLaneContract
{
    public const OBJECTIVE_KIND = 'cross_leverage';

    /**
     * @return list<array{objective:string, payload:array<string,mixed>, members:list<string>}>
     */
    public function mint(AtlasLoopScopeComprehensionModel $model, string $repoRoot): array
    {
        rtrim($repoRoot, '/');

        $forbidden = array_fill_keys(array_map(
            static fn (string $path): string => ltrim($path, '/'),
            array_values($model->forbidden),
        ), true);
        $orphans = array_fill_keys(array_map(
            static fn (string $fqcn): string => ltrim($fqcn, '\\'),
            array_values($model->orphans),
        ), true);

        $orphanNodesBySubdir = [];
        foreach ($model->inventory as $node) {
            $path = ltrim((string) ($node['rel_path'] ?? ''), '/');
            $fqcn = ltrim((string) ($node['fqcn'] ?? ''), '\\');
            if ($path === '' || $fqcn === '' || isset($forbidden[$path])) {
                continue;
            }
            if (($node['is_forbidden'] ?? false) === true || ! isset($orphans[$fqcn])) {
                continue;
            }

            $orphanNodesBySubdir[$this->subdir($path)][] = [
                'path' => $path,
                'fqcn' => $fqcn,
            ];
        }

        if ($orphanNodesBySubdir === []) {
            return [];
        }

        $specs = [];
        foreach ($model->cloneClusters as $cluster) {
            $clusterId = trim((string) ($cluster['cluster_id'] ?? ''));
            $members = array_values((array) ($cluster['members'] ?? []));
            if ($clusterId === '' || $members === []) {
                continue;
            }

            $clusterSubdir = $this->subdir(ltrim((string) ($members[0]['path'] ?? ''), '/'));
            $cloneMembers = [];
            foreach ($members as $member) {
                $path = ltrim((string) ($member['path'] ?? ''), '/');
                if ($path === '' || isset($forbidden[$path]) || $this->subdir($path) !== $clusterSubdir) {
                    continue;
                }
                $cloneMembers[$path] = true;
            }

            $cloneMembers = array_keys($cloneMembers);
            sort($cloneMembers);
            if (count($cloneMembers) < 2) {
                continue;
            }

            foreach ($orphanNodesBySubdir[$clusterSubdir] ?? [] as $orphan) {
                $orphanPath = (string) $orphan['path'];
                $orphanFqcn = (string) $orphan['fqcn'];
                $specs[] = [
                    'objective' => sprintf(
                        'Co-evolve %s (orphan) e o cluster duplicado em %s: wire o orphan no caller que sobrar apos a unificacao - uma decisao, dois ganhos materiais.',
                        $orphanFqcn,
                        $clusterId,
                    ),
                    'payload' => [
                        'objective_kind' => self::OBJECTIVE_KIND,
                        'source' => 'cross_leverage',
                        'orphan_fqcn' => $orphanFqcn,
                        'orphan_path' => $orphanPath,
                        'clone_cluster_id' => $clusterId,
                        'clone_members' => $cloneMembers,
                        'comprehension_originated' => true,
                        'red_required' => true,
                        'provenance' => AtlasLoopScopeComprehensionModel::PROVENANCE_WRITABLE_PROSE,
                    ],
                    'members' => array_values(array_merge([$orphanPath], $cloneMembers)),
                ];
            }
        }

        usort(
            $specs,
            static fn (array $a, array $b): int => strcmp((string) ($a['payload']['orphan_path'] ?? ''), (string) ($b['payload']['orphan_path'] ?? ''))
                ?: strcmp((string) ($a['payload']['clone_cluster_id'] ?? ''), (string) ($b['payload']['clone_cluster_id'] ?? '')),
        );

        return $specs;
    }

    private function subdir(string $path): string
    {
        $path = ltrim(trim($path), '/');
        if ($path === '') {
            return '';
        }

        return trim(str_replace('\\', '/', dirname($path)), './');
    }
}
