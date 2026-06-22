<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Discovery;

/**
 * Cohesive extracted cluster for {@see AtlasLoopOrphanWiringSupplyLane}.
 *
 * Owns the per-node admissibility evaluation (§5.6 rules 1-4): given one inventory
 * node and the precomputed lookup maps, decides whether the node qualifies as an
 * orphan-wiring directive and, if so, returns the resolved sibling evidence.
 *
 * Pulling this cluster out leaves the lane's mint loop with one decision (delegate
 * to the supporter) instead of the four compounded checks (orphan/forbidden/
 * public-methods/sibling-test) that previously bloated its cyclomatic count.
 */
final class AtlasLoopOrphanWiringSupplyLaneSupport
{
    /**
     * @param array<string,bool> $orphanFqcns Map of FQCN => true for every orphan reported by the model.
     * @param array<string,bool> $forbidden   Map of rel_path => true for pétreo/forbidden paths (defense-in-depth).
     * @param array<string,mixed> $node       One inventory node from {@see AtlasLoopScopeComprehensionModel::inventory}.
     *
     * @return array{admissible:bool, rel:string, fqcn:string, public_methods:list<string>, sibling:array<string,mixed>}|null
     *         Null when the node is skipped (missing rel/fqcn or fails rules 1-4). When `admissible` is false the
     *         caller continues; when true, the caller has the resolved inputs needed to mint a directive.
     */
    public function evaluateNodeAdmissibility(
        array $node,
        array $orphanFqcns,
        array $forbidden,
        AtlasLoopSiblingTestResolver $siblings,
    ): ?array {
        $rel = ltrim((string) ($node['rel_path'] ?? ''), '/');
        $fqcn = ltrim((string) ($node['fqcn'] ?? ''), '\\');
        if ($rel === '' || $fqcn === '') {
            return null;
        }

        // (1) real orphan + (2) not pétreo (model flag AND rel_path forbidden list, defense-in-depth).
        $isOrphan = ($node['is_orphan'] ?? false) === true && isset($orphanFqcns[$fqcn]);
        if (! $isOrphan || ($node['is_forbidden'] ?? false) === true || isset($forbidden[$rel])) {
            return null;
        }

        // (3) something to invoke.
        $publicMethods = array_values(array_filter((array) ($node['public_methods'] ?? []), 'is_string'));
        if ($publicMethods === []) {
            return null;
        }

        // (4) tested, working capability (not dead scaffolding to delete).
        $sib = $siblings->resolve($rel);
        if (($sib['has_sibling'] ?? false) !== true || ($sib['asserted_methods'] ?? []) === []) {
            return null;
        }

        return [
            'admissible' => true,
            'rel' => $rel,
            'fqcn' => $fqcn,
            'public_methods' => $publicMethods,
            'sibling' => $sib,
        ];
    }
}