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
     * @param  array<string,mixed>  $candidate scope candidate (id required, label optional)
     * @param  array<string,mixed>  $facts     readiness facts
     * @return array<string,mixed>
     */
    public function evaluate(array $candidate, array $facts): array
    {
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

        // Optional freshness facts — missing ⇒ HOLD; explicit false ⇒ BLOCK.
        $holdReasons = [];
        $this->freshnessSignal($facts, 'docs_health', $blockers, $holdReasons, $warnings);
        $this->freshnessSignal($facts, 'code_intelligence_ready', $blockers, $holdReasons, $warnings);
        $this->freshnessSignal($facts, 'knowledge_sync_current', $blockers, $holdReasons, $warnings);
        $this->freshnessSignal($facts, 'test_suite_green', $blockers, $holdReasons, $warnings);
        $this->freshnessSignal($facts, 'worker_capacity_available', $blockers, $holdReasons, $warnings);

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

        $envelope = [
            'schema_version' => self::SCHEMA,
            'status' => $status,
            'ready' => $status === self::STATUS_READY,
            'candidate_id' => (string) ($candidate['id'] ?? ''),
            'blockers' => $blockers,
            'hold_reasons' => $holdReasons,
            'warnings' => $warnings,
            'evidence_refs' => $evidenceRefs,
        ];
        $envelope['readiness_hash'] = $this->hash($envelope);

        return $envelope;
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
