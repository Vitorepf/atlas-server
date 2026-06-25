<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Discovery\Cortex\Diff;

use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopScopeComprehensionModel;

/**
 * Facts-only descriptive diff between two AtlasLoopScopeComprehensionModel snapshots.
 *
 * NO scoring. NO ranking. NO aggregate number. A diff is a SET of named transitions —
 * never a scalar. doc_purposes prose deltas are tagged with the model's
 * PROVENANCE_WRITABLE_PROSE and surface in a SEPARATE prose section so they can never
 * launder into a structural transition.
 *
 * Categories (all are arrays; identity ⇒ every list is empty):
 *   - inventory.added / inventory.removed                  (fqcn list)
 *   - inventory.shape_changed                              (per-fqcn changed-field list)
 *   - orphans.appeared / orphans.resolved                  (fqcn list)
 *   - edges.added / edges.removed                          (rel_path => caller list deltas)
 *   - clone_clusters.appeared / clone_clusters.dissolved   (cluster_id list)
 *   - clone_clusters.shape_changed                         (cluster_id list)
 *   - forbidden.added / forbidden.removed                  (rel_path list)
 *   - doc_stated_gaps.opened / doc_stated_gaps.closed      (symbol-name list)
 *   - doc_purposes_prose                                   (separate prose section)
 */
final class AtlasCortexSnapshotDiffEngine
{
    public const SCHEMA = 'atlas.cortex.snapshot_diff.v1';

    /**
     * @return array<string,mixed>
     */
    public function diff(AtlasLoopScopeComprehensionModel $left, AtlasLoopScopeComprehensionModel $right): array
    {
        $leftInventory = $this->indexByFqcn($left->inventory);
        $rightInventory = $this->indexByFqcn($right->inventory);

        $invAdded = array_values(array_diff(array_keys($rightInventory), array_keys($leftInventory)));
        $invRemoved = array_values(array_diff(array_keys($leftInventory), array_keys($rightInventory)));
        sort($invAdded, SORT_STRING);
        sort($invRemoved, SORT_STRING);

        $shapeChanged = $this->inventoryShapeChanges($leftInventory, $rightInventory);

        $orphanLeft = $this->normalizeOrphanList($left->orphans);
        $orphanRight = $this->normalizeOrphanList($right->orphans);
        $orphAppeared = array_values(array_diff($orphanRight, $orphanLeft));
        $orphResolved = array_values(array_diff($orphanLeft, $orphanRight));
        sort($orphAppeared, SORT_STRING);
        sort($orphResolved, SORT_STRING);

        $edgesAdded = $this->edgeDelta($left->edges, $right->edges, 'right_minus_left');
        $edgesRemoved = $this->edgeDelta($left->edges, $right->edges, 'left_minus_right');

        $clusterLeft = $this->indexByClusterId($left->cloneClusters);
        $clusterRight = $this->indexByClusterId($right->cloneClusters);
        $clusterAppeared = array_values(array_diff(array_keys($clusterRight), array_keys($clusterLeft)));
        $clusterDissolved = array_values(array_diff(array_keys($clusterLeft), array_keys($clusterRight)));
        sort($clusterAppeared, SORT_STRING);
        sort($clusterDissolved, SORT_STRING);
        $clusterShape = $this->cloneClusterShapeChanges($clusterLeft, $clusterRight);

        $forbAdded = array_values(array_diff($right->forbidden, $left->forbidden));
        $forbRemoved = array_values(array_diff($left->forbidden, $right->forbidden));
        sort($forbAdded, SORT_STRING);
        sort($forbRemoved, SORT_STRING);

        $gapsOpened = array_values(array_diff($right->docStatedGaps, $left->docStatedGaps));
        $gapsClosed = array_values(array_diff($left->docStatedGaps, $right->docStatedGaps));
        sort($gapsOpened, SORT_STRING);
        sort($gapsClosed, SORT_STRING);

        $proseDelta = $this->prose($left->docPurposes, $right->docPurposes);

        return [
            'schema_version' => self::SCHEMA,
            'left' => ['snapshot_id' => $left->snapshotId],
            'right' => ['snapshot_id' => $right->snapshotId],
            'inventory' => [
                'added' => $invAdded,
                'removed' => $invRemoved,
                'shape_changed' => $shapeChanged,
            ],
            'orphans' => [
                'appeared' => $orphAppeared,
                'resolved' => $orphResolved,
            ],
            'edges' => [
                'added' => $edgesAdded,
                'removed' => $edgesRemoved,
            ],
            'clone_clusters' => [
                'appeared' => $clusterAppeared,
                'dissolved' => $clusterDissolved,
                'shape_changed' => $clusterShape,
            ],
            'forbidden' => [
                'added' => $forbAdded,
                'removed' => $forbRemoved,
            ],
            'doc_stated_gaps' => [
                'opened' => $gapsOpened,
                'closed' => $gapsClosed,
            ],
            'doc_purposes_prose' => [
                'provenance' => AtlasLoopScopeComprehensionModel::PROVENANCE_WRITABLE_PROSE,
                'changed' => $proseDelta,
            ],
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $inventory
     * @return array<string, array<string,mixed>>
     */
    private function indexByFqcn(array $inventory): array
    {
        $out = [];
        foreach ($inventory as $item) {
            $fqcn = ltrim((string) ($item['fqcn'] ?? ''), '\\');
            if ($fqcn === '') {
                continue;
            }
            $out[$fqcn] = $item;
        }
        ksort($out);

        return $out;
    }

    /**
     * @param  array<string, array<string,mixed>>  $left
     * @param  array<string, array<string,mixed>>  $right
     * @return list<array{fqcn:string, changed_fields:list<string>}>
     */
    private function inventoryShapeChanges(array $left, array $right): array
    {
        $common = array_intersect_key($left, $right);
        $out = [];
        foreach (array_keys($common) as $fqcn) {
            $changed = [];
            $a = $left[$fqcn];
            $b = $right[$fqcn];

            $aMethods = array_values((array) ($a['public_methods'] ?? []));
            $bMethods = array_values((array) ($b['public_methods'] ?? []));
            sort($aMethods, SORT_STRING);
            sort($bMethods, SORT_STRING);
            if ($aMethods !== $bMethods) {
                $changed[] = 'public_methods';
            }
            if ((bool) ($a['is_orphan'] ?? false) !== (bool) ($b['is_orphan'] ?? false)) {
                $changed[] = 'is_orphan';
            }
            if ((bool) ($a['is_forbidden'] ?? false) !== (bool) ($b['is_forbidden'] ?? false)) {
                $changed[] = 'is_forbidden';
            }
            if ((string) ($a['clone_cluster_id'] ?? '') !== (string) ($b['clone_cluster_id'] ?? '')) {
                $changed[] = 'clone_cluster_id';
            }
            if ($changed !== []) {
                sort($changed, SORT_STRING);
                $out[] = ['fqcn' => $fqcn, 'changed_fields' => $changed];
            }
        }
        usort($out, static fn (array $a, array $b): int => strcmp($a['fqcn'], $b['fqcn']));

        return $out;
    }

    /**
     * @param  list<string>  $orphans
     * @return list<string>
     */
    private function normalizeOrphanList(array $orphans): array
    {
        return array_values(array_map(static fn ($f): string => ltrim((string) $f, '\\'), $orphans));
    }

    /**
     * @param  array<string, list<string>>  $left
     * @param  array<string, list<string>>  $right
     * @return array<string, list<string>>
     */
    private function edgeDelta(array $left, array $right, string $direction): array
    {
        $a = $direction === 'right_minus_left' ? $right : $left;
        $b = $direction === 'right_minus_left' ? $left : $right;
        $out = [];
        foreach ($a as $path => $callers) {
            $bCallers = (array) ($b[$path] ?? []);
            $delta = array_values(array_diff($callers, $bCallers));
            if ($delta !== []) {
                sort($delta, SORT_STRING);
                $out[$path] = $delta;
            }
        }
        ksort($out);

        return $out;
    }

    /**
     * @param  list<array<string,mixed>>  $clusters
     * @return array<string, array<string,mixed>>
     */
    private function indexByClusterId(array $clusters): array
    {
        $out = [];
        foreach ($clusters as $c) {
            $id = (string) ($c['cluster_id'] ?? '');
            if ($id === '') {
                continue;
            }
            $out[$id] = $c;
        }
        ksort($out);

        return $out;
    }

    /**
     * @param  array<string, array<string,mixed>>  $left
     * @param  array<string, array<string,mixed>>  $right
     * @return list<string>
     */
    private function cloneClusterShapeChanges(array $left, array $right): array
    {
        $common = array_intersect_key($left, $right);
        $changed = [];
        foreach (array_keys($common) as $id) {
            $a = $this->normalizeClusterShape($left[$id]);
            $b = $this->normalizeClusterShape($right[$id]);
            if ($a !== $b) {
                $changed[] = $id;
            }
        }
        sort($changed, SORT_STRING);

        return $changed;
    }

    /**
     * @param  array<string,mixed>  $cluster
     * @return array<string,mixed>
     */
    private function normalizeClusterShape(array $cluster): array
    {
        $members = array_values((array) ($cluster['members'] ?? []));
        usort($members, static fn (array $a, array $b): int => strcmp((string) ($a['path'] ?? '').':'.(string) ($a['symbol'] ?? ''), (string) ($b['path'] ?? '').':'.(string) ($b['symbol'] ?? '')));

        return [
            'clone_hash' => (string) ($cluster['clone_hash'] ?? ''),
            'members' => $members,
        ];
    }

    /**
     * @param  array<string,string>  $left
     * @param  array<string,string>  $right
     * @return list<array{fqcn:string, was:?string, now:?string}>
     */
    private function prose(array $left, array $right): array
    {
        $keys = array_unique(array_merge(array_keys($left), array_keys($right)));
        sort($keys, SORT_STRING);
        $out = [];
        foreach ($keys as $fqcn) {
            $a = $left[$fqcn] ?? null;
            $b = $right[$fqcn] ?? null;
            if ((string) $a !== (string) $b) {
                $out[] = [
                    'fqcn' => $fqcn,
                    'was' => $a,
                    'now' => $b,
                ];
            }
        }

        return $out;
    }
}
