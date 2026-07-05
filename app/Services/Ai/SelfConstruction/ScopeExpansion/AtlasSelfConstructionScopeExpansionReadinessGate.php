<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ScopeExpansion;

/**
 * Fail-closed readiness gate that decides whether Atlas may move a ranked scope candidate from the
 * candidate list to lane planning.
 *
 * Pass requires (every fact must be present AND meet the contract — missing ⇒ fail-closed):
 *   - current_scope_green = true
 *   - autonomy_allows_expansion = true
 *   - unresolved_critical_blockers = [] (empty)
 *   - rollback_gate_ready = true
 *   - release_governor_ready = true
 *   - queue_health.acceptable = true
 *   - requires_operator = false
 *   - requires_human = false
 *   - requires_external_provider = false
 *   - final_runtime_owner = 'atlas_native'
 *
 * Cross-project expansion (candidate.target_project_id differs from facts.current_project_id) adds a
 * mandatory contract on top of the above — missing OR explicitly false ⇒ BLOCK, never HOLD, since a
 * lane boundary is a safety property, not a freshness signal:
 *   - lane_isolation_evidence = true
 *   - project_receipt_policy = true
 *   - bounded_proof_plan = true
 *
 * Optional freshness signals (docs/code_intelligence): when absent ⇒ HOLD (not BLOCK); when
 * explicitly false ⇒ BLOCK (we honestly never fabricate readiness).
 *
 * Pure. No file/process/provider/git/queue side effects.
 */
final class AtlasSelfConstructionScopeExpansionReadinessGate
{
    public const SCHEMA = 'atlas.self_construction.scope_expansion_readiness_gate.v1';

    public const STATUS_READY = 'ready';
    public const STATUS_HOLD = 'hold';
    public const STATUS_BLOCKED = 'blocked';

    /**
     * Routed contract: evaluate($candidate, $facts) runs the full fail-closed candidate gate;
     * evaluate($laneFacts) (single argument) runs the standalone cross-project lane floor
     * introduced by the r122 hardening (kept intact, never weakened).
     *
     * @param  array<string,mixed>  $candidate scope candidate (id required, label optional) — or lane facts when $facts is null
     * @param  array<string,mixed>|null  $facts readiness facts
     * @return array<string,mixed>
     */
    public function evaluate(array $candidate, ?array $facts = null): array
    {
        if ($facts === null) {
            return $this->evaluateLaneFloor($candidate);
        }

        $blockers = [];
        $warnings = [];

        // Mandatory hard contract.
        $this->mustEqual($facts, 'current_scope_green', true, $blockers, 'current_scope_not_green');
        $this->mustEqual($facts, 'autonomy_allows_expansion', true, $blockers, 'autonomy_disallows_expansion');
        $this->mustEqual($facts, 'rollback_gate_ready', true, $blockers, 'rollback_gate_not_ready');
        $this->mustEqual($facts, 'release_governor_ready', true, $blockers, 'release_governor_not_ready');

        $critical = (array) ($facts['unresolved_critical_blockers'] ?? []);
        if ($critical !== []) {
            foreach ($critical as $b) {
                $blockers[] = 'unresolved_critical_blocker:'.(string) $b;
            }
        }

        $queueHealth = is_array($facts['queue_health'] ?? null) ? $facts['queue_health'] : [];
        if (! (bool) ($queueHealth['acceptable'] ?? false)) {
            $blockers[] = 'queue_health_not_acceptable';
        }

        // Autonomy / dependency contract — any non-Atlas dependency is BLOCK.
        $this->requireFalse($facts, 'requires_operator', $blockers, 'requires_operator');
        $this->requireFalse($facts, 'requires_human', $blockers, 'requires_human');
        $this->requireFalse($facts, 'requires_external_provider', $blockers, 'requires_external_provider');
        $finalOwner = (string) ($facts['final_runtime_owner'] ?? '');
        if ($finalOwner !== 'atlas_native') {
            $blockers[] = 'final_runtime_owner_not_atlas_native:'.$finalOwner;
        }

        // Cross-project expansion is a lane-boundary safety property, not freshness — missing OR
        // false must BLOCK, never merely HOLD.
        $targetProjectId = (string) ($candidate['target_project_id'] ?? '');
        $currentProjectId = (string) ($facts['current_project_id'] ?? '');
        if ($targetProjectId !== '' && $targetProjectId !== $currentProjectId) {
            $this->mustEqual($facts, 'lane_isolation_evidence', true, $blockers, 'lane_isolation_evidence_not_ready');
            $this->mustEqual($facts, 'project_receipt_policy', true, $blockers, 'project_receipt_policy_not_ready');
            $this->mustEqual($facts, 'bounded_proof_plan', true, $blockers, 'bounded_proof_plan_not_ready');
        }

        // Optional freshness facts — missing ⇒ HOLD; explicit false ⇒ BLOCK.
        $holdReasons = [];
        $this->freshnessSignal($facts, 'docs_health', $blockers, $holdReasons, $warnings);
        $this->freshnessSignal($facts, 'code_intelligence_ready', $blockers, $holdReasons, $warnings);
        $this->freshnessSignal($facts, 'knowledge_sync_current', $blockers, $holdReasons, $warnings);
        $this->freshnessSignal($facts, 'test_suite_green', $blockers, $holdReasons, $warnings);
        $this->freshnessSignal($facts, 'worker_capacity_available', $blockers, $holdReasons, $warnings);
        // Proof artifact freshness — expansion cannot proceed without these evidence receipts.
        $this->freshnessSignal($facts, 'context_pack_fresh', $blockers, $holdReasons, $warnings);
        $this->freshnessSignal($facts, 'queue_health_evidence', $blockers, $holdReasons, $warnings);
        $this->freshnessSignal($facts, 'rollback_evidence', $blockers, $holdReasons, $warnings);
        $this->freshnessSignal($facts, 'proof_plan_bounded', $blockers, $holdReasons, $warnings);

        $status = self::STATUS_READY;
        if ($blockers !== []) {
            $status = self::STATUS_BLOCKED;
        } elseif ($holdReasons !== []) {
            $status = self::STATUS_HOLD;
        }

        sort($blockers, SORT_STRING);
        sort($holdReasons, SORT_STRING);
        sort($warnings, SORT_STRING);

        $evidenceRefs = array_values((array) ($facts['evidence_refs'] ?? []));
        sort($evidenceRefs, SORT_STRING);

        $refreshActions = [];
        foreach ($holdReasons as $reason) {
            $key = str_replace('optional_freshness_missing:', '', $reason);
            $refreshActions[] = "refresh_{$key}_before_expansion";
        }

        $expansionScope = [
            'candidate_id' => (string) ($candidate['id'] ?? ''),
            'candidate_label' => (string) ($candidate['label'] ?? ''),
            'target_project_id' => (string) ($candidate['target_project_id'] ?? $facts['current_project_id'] ?? ''),
            'current_project_id' => (string) ($facts['current_project_id'] ?? ''),
            'is_cross_project' => $targetProjectId !== '' && $targetProjectId !== $currentProjectId,
        ];

        $envelope = [
            'schema_version' => self::SCHEMA,
            'status' => $status,
            'ready' => $status === self::STATUS_READY,
            'candidate_id' => (string) ($candidate['id'] ?? ''),
            'blockers' => $blockers,
            'hold_reasons' => $holdReasons,
            'warnings' => $warnings,
            'evidence_refs' => $evidenceRefs,
            'refresh_actions' => $refreshActions,
            'expansion_scope' => $expansionScope,
        ];
        $envelope['readiness_hash'] = $this->hash($envelope);

        return $envelope;
    }

    /**
     * Standalone cross-project lane floor (r122 hardening, kept verbatim): blocks unless lane
     * isolation, project receipt policy, and bounded proof plan evidence are all present.
     *
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
    private function evaluateLaneFloor(array $facts): array
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

    /**
     * @param  array<string,mixed>  $facts
     * @param  list<string>  $blockers
     */
    private function mustEqual(array $facts, string $key, bool $expected, array &$blockers, string $blockerName): void
    {
        if (! array_key_exists($key, $facts)) {
            $blockers[] = 'missing_mandatory_fact:'.$key;

            return;
        }
        if ((bool) $facts[$key] !== $expected) {
            $blockers[] = $blockerName;
        }
    }

    /**
     * @param  array<string,mixed>  $facts
     * @param  list<string>  $blockers
     */
    private function requireFalse(array $facts, string $key, array &$blockers, string $blockerName): void
    {
        if (! array_key_exists($key, $facts)) {
            $blockers[] = 'missing_mandatory_fact:'.$key;

            return;
        }
        if ((bool) $facts[$key] !== false) {
            $blockers[] = $blockerName;
        }
    }

    /**
     * @param  array<string,mixed>  $facts
     * @param  list<string>  $blockers
     * @param  list<string>  $holdReasons
     * @param  list<string>  $warnings
     */
    private function freshnessSignal(array $facts, string $key, array &$blockers, array &$holdReasons, array &$warnings): void
    {
        if (! array_key_exists($key, $facts)) {
            $holdReasons[] = 'optional_freshness_missing:'.$key;
            $warnings[] = 'fact_absent:'.$key;

            return;
        }
        if ((bool) $facts[$key] !== true) {
            $blockers[] = $key.'_not_ready';
        }
    }

    /**
     * @param  array<string,mixed>  $envelope
     */
    private function hash(array $envelope): string
    {
        $canonical = $envelope;
        unset($canonical['readiness_hash']);
        ksort($canonical);

        return hash('sha256', (string) json_encode($canonical, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
}
