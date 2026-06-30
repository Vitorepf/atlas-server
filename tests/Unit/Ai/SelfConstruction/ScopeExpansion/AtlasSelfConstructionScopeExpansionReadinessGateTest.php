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

    public function test_gate_source_has_no_side_effects(): void
    {
        $src = (string) file_get_contents(base_path('app/Services/Ai/SelfConstruction/ScopeExpansion/AtlasSelfConstructionScopeExpansionReadinessGate.php'));
        foreach (['file_put_contents', 'fopen', 'shell_exec', 'proc_open', 'exec(', 'system(', 'Http::', 'Queue::', 'DB::'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $src, "readiness gate must NOT contain {$forbidden}");
        }
    }
}
