<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Discovery;

/**
 * §5.6 · LAYER 2 (substrate) — turns the descriptive {@see AtlasLoopScopeComprehensionModel} into GROUNDED
 * origination CANDIDATES: net-new work the proxy discovery (cyclomatic/coverage scan of files-that-exist)
 * STRUCTURALLY cannot surface — a built-but-unwired capability, a structural clone to unify, a capability the
 * canonical docs demand but no symbol provides.
 *
 * GROUNDED BY CONSTRUCTION (anti-hallucination): every candidate is BUILT FROM the model's fact SETS and
 * carries the exact `evidence` (the real fqcn / clone hash / doc gap) that justifies it. A candidate that does
 * not cite a real model fact cannot exist — there is no free-text origination here. The petreo/FORBIDDEN set is
 * excluded up front (the loop never originates work on its own judge/merge organs).
 *
 * ANTI-GOODHART (the load-bearing invariant): this producer emits a LIST of grounded candidate DESCRIPTIONS and
 * **NO score / ranking**. WHICH candidate is the highest-leverage evolution is the frontier model's (honestly
 * model-bound) judgment — never a scalar computed here (that would be the cyclomatic proxy reborn one level up).
 * Pure + deterministic: a function of the model only (no DB, provider, or clock).
 */
final class AtlasLoopComprehensionOriginationCandidates
{
    public const KIND_ORPHAN_WIRING = 'orphan_wiring';

    public const KIND_CLONE_UNIFICATION = 'clone_unification';

    public const KIND_DOC_GAP_CAPABILITY = 'doc_gap_capability';

    /**
     * Emit the grounded origination candidates for a comprehension model (deterministic order; no rank).
     *
     * @return list<array{kind:string, summary:string, target_path:?string, target_fqcn:?string,
     *                     members:list<array{path:string, symbol:string}>, capability:?string,
     *                     evidence:array<string,mixed>}>
     */
    public function forModel(AtlasLoopScopeComprehensionModel $model): array
    {
        $forbidden = array_fill_keys($model->forbidden, true);
        $fqcnToPath = [];
        foreach ($model->inventory as $item) {
            $fqcnToPath[(string) $item['fqcn']] = (string) $item['rel_path'];
        }

        $candidates = [];

        // 1. ORPHAN-WIRING — a built-but-unwired capability. The DOC purpose (if any) is advisory context only.
        foreach ($model->orphans as $fqcn) {
            $path = $fqcnToPath[$fqcn] ?? null;
            if ($path === null || isset($forbidden[$path])) {
                continue; // never originate on a petreo/forbidden target
            }
            $candidates[] = [
                'kind' => self::KIND_ORPHAN_WIRING,
                'summary' => "The capability {$fqcn} is built but has NO production caller — wire it into the live path (or retire it).",
                'target_path' => $path,
                'target_fqcn' => $fqcn,
                'members' => [],
                'capability' => null,
                'evidence' => [
                    'is_orphan' => true,
                    'snapshot_id' => $model->snapshotId,
                    'doc_purpose' => $model->docPurposes[$fqcn] ?? null,
                ],
            ];
        }

        // 2. CLONE-UNIFICATION — N structural clones that should collapse to one (real duplication/coupling debt).
        foreach ($model->cloneClusters as $cluster) {
            $members = array_values(array_filter(
                $cluster['members'],
                static fn (array $m): bool => ! isset($forbidden[ltrim((string) $m['path'], '/')]),
            ));
            if (count($members) < 2) {
                continue; // a cluster reduced below 2 by the forbidden filter is no longer a unification
            }
            $paths = implode(', ', array_map(static fn (array $m): string => (string) $m['path'], $members));
            $candidates[] = [
                'kind' => self::KIND_CLONE_UNIFICATION,
                'summary' => 'Structural clones across '.count($members)." symbols ({$paths}) — unify them behind one implementation.",
                'target_path' => (string) $members[0]['path'],
                'target_fqcn' => null,
                'members' => array_map(static fn (array $m): array => [
                    'path' => (string) $m['path'],
                    'symbol' => (string) $m['symbol'],
                ], $members),
                'capability' => null,
                'evidence' => [
                    'clone_cluster_id' => (string) $cluster['cluster_id'],
                    'clone_hash' => (string) $cluster['clone_hash'],
                    'snapshot_id' => $model->snapshotId,
                ],
            ];
        }

        // 3. DOC-GAP CAPABILITY — the canonical docs NAME a capability that resolves to no symbol (build it).
        foreach ($model->docStatedGaps as $capability) {
            $candidates[] = [
                'kind' => self::KIND_DOC_GAP_CAPABILITY,
                'summary' => "The canonical docs require `{$capability}`, but no symbol provides it — originate the missing capability.",
                'target_path' => null,
                'target_fqcn' => null,
                'members' => [],
                'capability' => $capability,
                'evidence' => [
                    'doc_stated_gap' => $capability,
                    'provenance' => AtlasLoopScopeComprehensionModel::PROVENANCE_WRITABLE_PROSE,
                    'snapshot_id' => $model->snapshotId,
                ],
            ];
        }

        return $candidates;
    }
}
