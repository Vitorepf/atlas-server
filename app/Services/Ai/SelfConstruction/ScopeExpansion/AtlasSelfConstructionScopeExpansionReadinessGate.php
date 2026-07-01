<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ScopeExpansion;

/**
 * Pure gate that blocks cross-project scope expansion unless lane isolation,
 * project receipt policy, and bounded proof plan evidence are all present.
 *
 * NO network I/O, NO file I/O, NO provider calls.
 */
final class AtlasSelfConstructionScopeExpansionReadinessGate
{
    public const SCHEMA = 'atlas.self_construction.scope_expansion_readiness_gate.v1';

    /**
     * @param  array{
     *   lane_isolation_evidence?:bool,
     *   project_receipt_policy?:bool,
     *   bounded_proof_plan?:bool,
     * }  $facts
     * @return array{
     *   schema:string,
     *   ready:bool,
     *   blockers:list<string>,
     * }
     */
    public function evaluate(array $facts): array
    {
        $blockers = [];

        if (($facts['lane_isolation_evidence'] ?? false) !== true) {
            $blockers[] = 'missing:lane_isolation_evidence';
        }
        if (($facts['project_receipt_policy'] ?? false) !== true) {
            $blockers[] = 'missing:project_receipt_policy';
        }
        if (($facts['bounded_proof_plan'] ?? false) !== true) {
            $blockers[] = 'missing:bounded_proof_plan';
        }

        sort($blockers, SORT_STRING);

        return [
            'schema' => self::SCHEMA,
            'ready' => count($blockers) === 0,
            'blockers' => $blockers,
        ];
    }
}
