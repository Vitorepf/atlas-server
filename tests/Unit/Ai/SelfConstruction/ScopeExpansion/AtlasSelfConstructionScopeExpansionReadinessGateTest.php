<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ScopeExpansion;

use App\Services\Ai\SelfConstruction\ScopeExpansion\AtlasSelfConstructionScopeExpansionReadinessGate;
use Tests\TestCase;

final class AtlasSelfConstructionScopeExpansionReadinessGateTest extends TestCase
{
    private function candidate(): array
    {
        return ['id' => 'cand-1', 'label' => 'expand authoring layer'];
    }

    /** @return array<string,mixed> */
    private function readyFacts(array $overrides = []): array
    {
        return array_replace_recursive([
            'current_scope_green' => true,
            'autonomy_allows_expansion' => true,
            'unresolved_critical_blockers' => [],
            'rollback_gate_ready' => true,
            'release_governor_ready' => true,
            'queue_health' => ['acceptable' => true],
            'requires_operator' => false,
            'requires_human' => false,
            'requires_external_provider' => false,
            'final_runtime_owner' => 'atlas_native',
            'docs_health' => true,
            'code_intelligence_ready' => true,
            'knowledge_sync_current' => true,
            'test_suite_green' => true,
            'worker_capacity_available' => true,
            'context_pack_fresh' => true,
            'queue_health_evidence' => true,
            'rollback_evidence' => true,
            'proof_plan_bounded' => true,
            'evidence_refs' => ['evidence:run-1'],
        ], $overrides);
    }

    public function test_ready_when_all_atlas_native_facts_pass(): void
    {
        $verdict = (new AtlasSelfConstructionScopeExpansionReadinessGate)->evaluate($this->candidate(), $this->readyFacts());

        $this->assertSame('atlas.self_construction.scope_expansion_readiness_gate.v1', $verdict['schema_version']);
        $this->assertSame('ready', $verdict['status']);
        $this->assertTrue($verdict['ready']);
        $this->assertSame([], $verdict['blockers']);
        $this->assertNotEmpty($verdict['readiness_hash']);
    }

    public function test_blocked_when_requires_operator(): void
    {
        $verdict = (new AtlasSelfConstructionScopeExpansionReadinessGate)->evaluate(
            $this->candidate(),
            $this->readyFacts(['requires_operator' => true]),
        );
        $this->assertSame('blocked', $verdict['status']);
        $this->assertContains('requires_operator', $verdict['blockers']);
    }

    public function test_blocked_when_requires_human(): void
    {
        $verdict = (new AtlasSelfConstructionScopeExpansionReadinessGate)->evaluate(
            $this->candidate(),
            $this->readyFacts(['requires_human' => true]),
        );
        $this->assertSame('blocked', $verdict['status']);
        $this->assertContains('requires_human', $verdict['blockers']);
    }

    public function test_blocked_when_external_provider_dependency_present(): void
    {
        $verdict = (new AtlasSelfConstructionScopeExpansionReadinessGate)->evaluate(
            $this->candidate(),
            $this->readyFacts(['requires_external_provider' => true]),
        );
        $this->assertSame('blocked', $verdict['status']);
        $this->assertContains('requires_external_provider', $verdict['blockers']);
    }

    public function test_blocked_when_final_runtime_owner_not_atlas_native(): void
    {
        $verdict = (new AtlasSelfConstructionScopeExpansionReadinessGate)->evaluate(
            $this->candidate(),
            $this->readyFacts(['final_runtime_owner' => 'claude_code']),
        );
        $this->assertSame('blocked', $verdict['status']);
        $this->assertContains('final_runtime_owner_not_atlas_native:claude_code', $verdict['blockers']);
    }

    public function test_blocked_when_current_scope_not_green(): void
    {
        $verdict = (new AtlasSelfConstructionScopeExpansionReadinessGate)->evaluate(
            $this->candidate(),
            $this->readyFacts(['current_scope_green' => false]),
        );
        $this->assertSame('blocked', $verdict['status']);
        $this->assertContains('current_scope_not_green', $verdict['blockers']);
    }

    public function test_blocked_when_autonomy_disallows_expansion(): void
    {
        $verdict = (new AtlasSelfConstructionScopeExpansionReadinessGate)->evaluate(
            $this->candidate(),
            $this->readyFacts(['autonomy_allows_expansion' => false]),
        );
        $this->assertSame('blocked', $verdict['status']);
        $this->assertContains('autonomy_disallows_expansion', $verdict['blockers']);
    }

    public function test_blocked_when_critical_blockers_present(): void
    {
        $verdict = (new AtlasSelfConstructionScopeExpansionReadinessGate)->evaluate(
            $this->candidate(),
            $this->readyFacts(['unresolved_critical_blockers' => ['merge_conflict', 'rollback_failure']]),
        );
        $this->assertSame('blocked', $verdict['status']);
        $this->assertContains('unresolved_critical_blocker:merge_conflict', $verdict['blockers']);
        $this->assertContains('unresolved_critical_blocker:rollback_failure', $verdict['blockers']);
    }

    public function test_blocked_when_rollback_or_release_gates_not_ready(): void
    {
        $verdict = (new AtlasSelfConstructionScopeExpansionReadinessGate)->evaluate(
            $this->candidate(),
            $this->readyFacts(['rollback_gate_ready' => false, 'release_governor_ready' => false]),
        );
        $this->assertSame('blocked', $verdict['status']);
        $this->assertContains('rollback_gate_not_ready', $verdict['blockers']);
        $this->assertContains('release_governor_not_ready', $verdict['blockers']);
    }

    public function test_blocked_when_queue_health_unacceptable(): void
    {
        $verdict = (new AtlasSelfConstructionScopeExpansionReadinessGate)->evaluate(
            $this->candidate(),
            $this->readyFacts(['queue_health' => ['acceptable' => false]]),
        );
        $this->assertSame('blocked', $verdict['status']);
        $this->assertContains('queue_health_not_acceptable', $verdict['blockers']);
    }

    public function test_hold_when_optional_freshness_missing(): void
    {
        $facts = $this->readyFacts();
        unset($facts['docs_health']);

        $verdict = (new AtlasSelfConstructionScopeExpansionReadinessGate)->evaluate($this->candidate(), $facts);
        $this->assertSame('hold', $verdict['status']);
        $this->assertContains('optional_freshness_missing:docs_health', $verdict['hold_reasons']);
        $this->assertContains('fact_absent:docs_health', $verdict['warnings']);
    }

    public function test_blocked_when_docs_health_explicitly_false(): void
    {
        $verdict = (new AtlasSelfConstructionScopeExpansionReadinessGate)->evaluate(
            $this->candidate(),
            $this->readyFacts(['docs_health' => false]),
        );
        $this->assertSame('blocked', $verdict['status']);
        $this->assertContains('docs_health_not_ready', $verdict['blockers']);
    }

    public function test_missing_mandatory_fact_is_fail_closed(): void
    {
        $facts = $this->readyFacts();
        unset($facts['rollback_gate_ready']);

        $verdict = (new AtlasSelfConstructionScopeExpansionReadinessGate)->evaluate($this->candidate(), $facts);
        $this->assertSame('blocked', $verdict['status']);
        $this->assertContains('missing_mandatory_fact:rollback_gate_ready', $verdict['blockers']);
    }

    public function test_readiness_hash_is_deterministic(): void
    {
        $a = (new AtlasSelfConstructionScopeExpansionReadinessGate)->evaluate($this->candidate(), $this->readyFacts());
        $b = (new AtlasSelfConstructionScopeExpansionReadinessGate)->evaluate($this->candidate(), $this->readyFacts());
        $this->assertSame($a['readiness_hash'], $b['readiness_hash']);
    }

    public function test_blocked_when_knowledge_sync_not_current(): void
    {
        $verdict = (new AtlasSelfConstructionScopeExpansionReadinessGate)->evaluate(
            $this->candidate(),
            $this->readyFacts(['knowledge_sync_current' => false]),
        );
        $this->assertSame('blocked', $verdict['status']);
        $this->assertContains('knowledge_sync_current_not_ready', $verdict['blockers']);
    }

    public function test_hold_when_knowledge_sync_absent(): void
    {
        $facts = $this->readyFacts();
        unset($facts['knowledge_sync_current']);
        $verdict = (new AtlasSelfConstructionScopeExpansionReadinessGate)->evaluate($this->candidate(), $facts);
        $this->assertSame('hold', $verdict['status']);
        $this->assertContains('optional_freshness_missing:knowledge_sync_current', $verdict['hold_reasons']);
    }

    public function test_blocked_when_test_suite_not_green(): void
    {
        $verdict = (new AtlasSelfConstructionScopeExpansionReadinessGate)->evaluate(
            $this->candidate(),
            $this->readyFacts(['test_suite_green' => false]),
        );
        $this->assertSame('blocked', $verdict['status']);
        $this->assertContains('test_suite_green_not_ready', $verdict['blockers']);
    }

    public function test_blocked_when_worker_capacity_unavailable(): void
    {
        $verdict = (new AtlasSelfConstructionScopeExpansionReadinessGate)->evaluate(
            $this->candidate(),
            $this->readyFacts(['worker_capacity_available' => false]),
        );
        $this->assertSame('blocked', $verdict['status']);
        $this->assertContains('worker_capacity_available_not_ready', $verdict['blockers']);
    }

    public function test_hold_when_worker_capacity_absent(): void
    {
        $facts = $this->readyFacts();
        unset($facts['worker_capacity_available']);
        $verdict = (new AtlasSelfConstructionScopeExpansionReadinessGate)->evaluate($this->candidate(), $facts);
        $this->assertSame('hold', $verdict['status']);
        $this->assertContains('optional_freshness_missing:worker_capacity_available', $verdict['hold_reasons']);
    }

    // --- proof artifact freshness tests ---

    public function test_hold_when_context_pack_fresh_absent(): void
    {
        $facts = $this->readyFacts();
        unset($facts['context_pack_fresh']);
        $verdict = (new AtlasSelfConstructionScopeExpansionReadinessGate)->evaluate($this->candidate(), $facts);
        $this->assertSame('hold', $verdict['status']);
        $this->assertContains('optional_freshness_missing:context_pack_fresh', $verdict['hold_reasons']);
    }

    public function test_blocked_when_context_pack_fresh_explicitly_false(): void
    {
        $verdict = (new AtlasSelfConstructionScopeExpansionReadinessGate)->evaluate(
            $this->candidate(),
            $this->readyFacts(['context_pack_fresh' => false]),
        );
        $this->assertSame('blocked', $verdict['status']);
        $this->assertContains('context_pack_fresh_not_ready', $verdict['blockers']);
    }

    public function test_hold_when_queue_health_evidence_absent(): void
    {
        $facts = $this->readyFacts();
        unset($facts['queue_health_evidence']);
        $verdict = (new AtlasSelfConstructionScopeExpansionReadinessGate)->evaluate($this->candidate(), $facts);
        $this->assertSame('hold', $verdict['status']);
        $this->assertContains('optional_freshness_missing:queue_health_evidence', $verdict['hold_reasons']);
    }

    public function test_blocked_when_queue_health_evidence_explicitly_false(): void
    {
        $verdict = (new AtlasSelfConstructionScopeExpansionReadinessGate)->evaluate(
            $this->candidate(),
            $this->readyFacts(['queue_health_evidence' => false]),
        );
        $this->assertSame('blocked', $verdict['status']);
        $this->assertContains('queue_health_evidence_not_ready', $verdict['blockers']);
    }

    public function test_hold_when_rollback_evidence_absent(): void
    {
        $facts = $this->readyFacts();
        unset($facts['rollback_evidence']);
        $verdict = (new AtlasSelfConstructionScopeExpansionReadinessGate)->evaluate($this->candidate(), $facts);
        $this->assertSame('hold', $verdict['status']);
        $this->assertContains('optional_freshness_missing:rollback_evidence', $verdict['hold_reasons']);
    }

    public function test_blocked_when_rollback_evidence_explicitly_false(): void
    {
        $verdict = (new AtlasSelfConstructionScopeExpansionReadinessGate)->evaluate(
            $this->candidate(),
            $this->readyFacts(['rollback_evidence' => false]),
        );
        $this->assertSame('blocked', $verdict['status']);
        $this->assertContains('rollback_evidence_not_ready', $verdict['blockers']);
    }

    public function test_hold_when_proof_plan_bounded_absent(): void
    {
        $facts = $this->readyFacts();
        unset($facts['proof_plan_bounded']);
        $verdict = (new AtlasSelfConstructionScopeExpansionReadinessGate)->evaluate($this->candidate(), $facts);
        $this->assertSame('hold', $verdict['status']);
        $this->assertContains('optional_freshness_missing:proof_plan_bounded', $verdict['hold_reasons']);
    }

    public function test_blocked_when_proof_plan_bounded_explicitly_false(): void
    {
        $verdict = (new AtlasSelfConstructionScopeExpansionReadinessGate)->evaluate(
            $this->candidate(),
            $this->readyFacts(['proof_plan_bounded' => false]),
        );
        $this->assertSame('blocked', $verdict['status']);
        $this->assertContains('proof_plan_bounded_not_ready', $verdict['blockers']);
    }

    public function test_ready_with_all_proof_artifacts_present(): void
    {
        $verdict = (new AtlasSelfConstructionScopeExpansionReadinessGate)->evaluate(
            $this->candidate(),
            $this->readyFacts(),
        );
        $this->assertSame('ready', $verdict['status']);
        $this->assertTrue($verdict['ready']);
        $this->assertArrayNotHasKey('asks_for_human', $verdict);
    }

    // --- cross-project expansion contract ---

    private function crossProjectCandidate(): array
    {
        return ['id' => 'cand-2', 'label' => 'expand into atlas-desktop', 'target_project_id' => 'atlas-desktop'];
    }

    public function test_ready_for_cross_project_expansion_when_lane_contract_present(): void
    {
        $verdict = (new AtlasSelfConstructionScopeExpansionReadinessGate)->evaluate(
            $this->crossProjectCandidate(),
            $this->readyFacts([
                'current_project_id' => 'atlas-server',
                'lane_isolation_evidence' => true,
                'project_receipt_policy' => true,
                'bounded_proof_plan' => true,
            ]),
        );
        $this->assertSame('ready', $verdict['status']);
        $this->assertTrue($verdict['ready']);
    }

    public function test_blocked_when_lane_isolation_evidence_missing_for_cross_project(): void
    {
        $verdict = (new AtlasSelfConstructionScopeExpansionReadinessGate)->evaluate(
            $this->crossProjectCandidate(),
            $this->readyFacts([
                'current_project_id' => 'atlas-server',
                'project_receipt_policy' => true,
                'bounded_proof_plan' => true,
            ]),
        );
        $this->assertSame('blocked', $verdict['status']);
        $this->assertContains('missing_mandatory_fact:lane_isolation_evidence', $verdict['blockers']);
    }

    public function test_blocked_when_project_receipt_policy_false_for_cross_project(): void
    {
        $verdict = (new AtlasSelfConstructionScopeExpansionReadinessGate)->evaluate(
            $this->crossProjectCandidate(),
            $this->readyFacts([
                'current_project_id' => 'atlas-server',
                'lane_isolation_evidence' => true,
                'project_receipt_policy' => false,
                'bounded_proof_plan' => true,
            ]),
        );
        $this->assertSame('blocked', $verdict['status']);
        $this->assertContains('project_receipt_policy_not_ready', $verdict['blockers']);
    }

    public function test_blocked_when_bounded_proof_plan_missing_for_cross_project(): void
    {
        $verdict = (new AtlasSelfConstructionScopeExpansionReadinessGate)->evaluate(
            $this->crossProjectCandidate(),
            $this->readyFacts([
                'current_project_id' => 'atlas-server',
                'lane_isolation_evidence' => true,
                'project_receipt_policy' => true,
            ]),
        );
        $this->assertSame('blocked', $verdict['status']);
        $this->assertContains('missing_mandatory_fact:bounded_proof_plan', $verdict['blockers']);
    }

    public function test_same_project_expansion_does_not_require_lane_contract(): void
    {
        $verdict = (new AtlasSelfConstructionScopeExpansionReadinessGate)->evaluate(
            ['id' => 'cand-3', 'target_project_id' => 'atlas-server'],
            $this->readyFacts(['current_project_id' => 'atlas-server']),
        );
        $this->assertSame('ready', $verdict['status']);
    }

    public function test_gate_source_has_no_side_effects(): void
    {
        $src = (string) file_get_contents(base_path('app/Services/Ai/SelfConstruction/ScopeExpansion/AtlasSelfConstructionScopeExpansionReadinessGate.php'));
        foreach (['file_put_contents', 'fopen', 'shell_exec', 'proc_open', 'exec(', 'system(', 'Http::', 'Queue::', 'DB::'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $src, "readiness gate must NOT contain {$forbidden}");
        }
    }

    // ---- r122 standalone lane floor: single-arg evaluate() routes to the lane contract ----

    // AC: blocks when any evidence missing or false
    public function test_missing_lane_isolation_blocked(): void
    {
        $result = (new AtlasSelfConstructionScopeExpansionReadinessGate)->evaluate([
            'project_receipt_policy' => true,
            'bounded_proof_plan' => true,
        ]);

        $this->assertFalse($result['ready']);
        $this->assertContains('missing:lane_isolation_evidence', $result['blockers']);
    }

    public function test_missing_project_receipt_policy_blocked(): void
    {
        $result = (new AtlasSelfConstructionScopeExpansionReadinessGate)->evaluate([
            'lane_isolation_evidence' => true,
            'bounded_proof_plan' => true,
        ]);

        $this->assertFalse($result['ready']);
        $this->assertContains('missing:project_receipt_policy', $result['blockers']);
    }

    public function test_missing_bounded_proof_plan_blocked(): void
    {
        $result = (new AtlasSelfConstructionScopeExpansionReadinessGate)->evaluate([
            'lane_isolation_evidence' => true,
            'project_receipt_policy' => true,
        ]);

        $this->assertFalse($result['ready']);
        $this->assertContains('missing:bounded_proof_plan', $result['blockers']);
    }

    public function test_all_false_blocked_with_all_three_blockers(): void
    {
        $result = (new AtlasSelfConstructionScopeExpansionReadinessGate)->evaluate([
            'lane_isolation_evidence' => false,
            'project_receipt_policy' => false,
            'bounded_proof_plan' => false,
        ]);

        $this->assertFalse($result['ready']);
        $this->assertCount(3, $result['blockers']);
    }

    public function test_all_present_passes(): void
    {
        $result = (new AtlasSelfConstructionScopeExpansionReadinessGate)->evaluate([
            'lane_isolation_evidence' => true,
            'project_receipt_policy' => true,
            'bounded_proof_plan' => true,
        ]);

        $this->assertTrue($result['ready']);
        $this->assertEmpty($result['blockers']);
    }

    // ── AC: missing autonomy proof, worker capacity, rollback or knowledge sync blocks expansion readiness ──

    public function test_missing_autonomy_proof_blocks_expansion(): void
    {
        $facts = $this->readyFacts();
        unset($facts['autonomy_allows_expansion']);

        $verdict = (new AtlasSelfConstructionScopeExpansionReadinessGate)->evaluate($this->candidate(), $facts);

        $this->assertSame('blocked', $verdict['status']);
        $this->assertContains('missing_mandatory_fact:autonomy_allows_expansion', $verdict['blockers']);
    }

    public function test_missing_worker_capacity_blocks_expansion(): void
    {
        $facts = $this->readyFacts();
        unset($facts['worker_capacity_available']);

        $verdict = (new AtlasSelfConstructionScopeExpansionReadinessGate)->evaluate($this->candidate(), $facts);

        $this->assertSame('hold', $verdict['status']);
        $this->assertContains('optional_freshness_missing:worker_capacity_available', $verdict['hold_reasons']);
    }

    public function test_missing_rollback_blocks_expansion(): void
    {
        $facts = $this->readyFacts();
        unset($facts['rollback_gate_ready']);

        $verdict = (new AtlasSelfConstructionScopeExpansionReadinessGate)->evaluate($this->candidate(), $facts);

        $this->assertSame('blocked', $verdict['status']);
        $this->assertContains('missing_mandatory_fact:rollback_gate_ready', $verdict['blockers']);
    }

    public function test_missing_knowledge_sync_blocks_expansion(): void
    {
        $facts = $this->readyFacts();
        unset($facts['knowledge_sync_current']);

        $verdict = (new AtlasSelfConstructionScopeExpansionReadinessGate)->evaluate($this->candidate(), $facts);

        $this->assertSame('hold', $verdict['status']);
        $this->assertContains('optional_freshness_missing:knowledge_sync_current', $verdict['hold_reasons']);
    }

    // ── AC: stale evidence produces status=hold with refresh actions ──

    public function test_stale_evidence_produces_hold_with_refresh_actions(): void
    {
        $facts = $this->readyFacts();
        unset($facts['docs_health'], $facts['knowledge_sync_current']);

        $verdict = (new AtlasSelfConstructionScopeExpansionReadinessGate)->evaluate($this->candidate(), $facts);

        $this->assertSame('hold', $verdict['status']);
        $this->assertNotEmpty($verdict['refresh_actions']);
        $this->assertContains('refresh_docs_health_before_expansion', $verdict['refresh_actions']);
        $this->assertContains('refresh_knowledge_sync_current_before_expansion', $verdict['refresh_actions']);
    }

    // ── AC: fresh complete evidence returns status=ready and expansion_scope summary ──

    public function test_fresh_complete_evidence_returns_ready_with_expansion_scope(): void
    {
        $verdict = (new AtlasSelfConstructionScopeExpansionReadinessGate)->evaluate($this->candidate(), $this->readyFacts());

        $this->assertSame('ready', $verdict['status']);
        $this->assertTrue($verdict['ready']);
        $this->assertArrayHasKey('expansion_scope', $verdict);
        $this->assertSame('cand-1', $verdict['expansion_scope']['candidate_id']);
        $this->assertSame('expand authoring layer', $verdict['expansion_scope']['candidate_label']);
    }
}
