<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\ScopeExpansion\AtlasSelfConstructionScopeExpansionReadinessGate;
use Tests\TestCase;

final class AtlasSelfConstructionScopeExpansionReadinessGateTest extends TestCase
{
    private AtlasSelfConstructionScopeExpansionReadinessGate $gate;

    protected function setUp(): void
    {
        parent::setUp();
        $this->gate = new AtlasSelfConstructionScopeExpansionReadinessGate;
    }

    private function fullFacts(array $overrides = []): array
    {
        return array_merge([
            // Hard mandatory facts
            'current_scope_green'       => true,
            'autonomy_allows_expansion' => true,
            'rollback_gate_ready'       => true,
            'release_governor_ready'    => true,
            'unresolved_critical_blockers' => [],
            'queue_health'              => ['acceptable' => true],
            'requires_operator'         => false,
            'requires_human'            => false,
            'requires_external_provider' => false,
            'final_runtime_owner'       => 'atlas_native',
            // Optional freshness signals
            'docs_health'               => true,
            'code_intelligence_ready'   => true,
            'knowledge_sync_current'    => true,
            'test_suite_green'          => true,
            'worker_capacity_available' => true,
            'context_pack_fresh'        => true,
            'queue_health_evidence'     => true,
            'rollback_evidence'         => true,
            'proof_plan_bounded'        => true,
            // Evidence refs
            'evidence_refs'             => ['rollback_cert', 'queue_snapshot', 'context_pack_ref'],
        ], $overrides);
    }

    private function candidate(string $id = 'scope-engineering-v2'): array
    {
        return ['id' => $id, 'label' => 'Engineering scope v2'];
    }

    // ── AC2: missing or false hard facts block readiness

    public function test_ac2_missing_current_scope_green_blocks(): void
    {
        $facts = $this->fullFacts();
        unset($facts['current_scope_green']);

        $result = $this->gate->evaluate($this->candidate(), $facts);

        $this->assertSame(AtlasSelfConstructionScopeExpansionReadinessGate::STATUS_BLOCKED, $result['status']);
        $this->assertFalse($result['ready']);
        $combined = implode(' ', $result['blockers']);
        $this->assertStringContainsString('current_scope_green', $combined);
    }

    public function test_ac2_false_autonomy_allows_expansion_blocks(): void
    {
        $result = $this->gate->evaluate($this->candidate(), $this->fullFacts(['autonomy_allows_expansion' => false]));

        $this->assertSame(AtlasSelfConstructionScopeExpansionReadinessGate::STATUS_BLOCKED, $result['status']);
        $this->assertContains('autonomy_disallows_expansion', $result['blockers']);
    }

    public function test_ac2_false_rollback_gate_ready_blocks(): void
    {
        $result = $this->gate->evaluate($this->candidate(), $this->fullFacts(['rollback_gate_ready' => false]));

        $this->assertSame(AtlasSelfConstructionScopeExpansionReadinessGate::STATUS_BLOCKED, $result['status']);
        $this->assertContains('rollback_gate_not_ready', $result['blockers']);
    }

    public function test_ac2_false_release_governor_ready_blocks(): void
    {
        $result = $this->gate->evaluate($this->candidate(), $this->fullFacts(['release_governor_ready' => false]));

        $this->assertSame(AtlasSelfConstructionScopeExpansionReadinessGate::STATUS_BLOCKED, $result['status']);
        $this->assertContains('release_governor_not_ready', $result['blockers']);
    }

    public function test_ac2_non_empty_critical_blockers_blocks(): void
    {
        $result = $this->gate->evaluate($this->candidate(), $this->fullFacts([
            'unresolved_critical_blockers' => ['missing-wiring-in-organ-X'],
        ]));

        $this->assertSame(AtlasSelfConstructionScopeExpansionReadinessGate::STATUS_BLOCKED, $result['status']);
        $combined = implode(' ', $result['blockers']);
        $this->assertStringContainsString('unresolved_critical_blocker:', $combined);
    }

    public function test_ac2_queue_health_not_acceptable_blocks(): void
    {
        $result = $this->gate->evaluate($this->candidate(), $this->fullFacts([
            'queue_health' => ['acceptable' => false],
        ]));

        $this->assertSame(AtlasSelfConstructionScopeExpansionReadinessGate::STATUS_BLOCKED, $result['status']);
        $this->assertContains('queue_health_not_acceptable', $result['blockers']);
    }

    public function test_ac2_non_atlas_native_owner_blocks(): void
    {
        $result = $this->gate->evaluate($this->candidate(), $this->fullFacts([
            'final_runtime_owner' => 'external_codex',
        ]));

        $this->assertSame(AtlasSelfConstructionScopeExpansionReadinessGate::STATUS_BLOCKED, $result['status']);
        $combined = implode(' ', $result['blockers']);
        $this->assertStringContainsString('final_runtime_owner_not_atlas_native', $combined);
    }

    // ── AC3: missing optional → hold; explicit false → blocked

    public function test_ac3_missing_optional_freshness_produces_hold(): void
    {
        $facts = $this->fullFacts();
        unset($facts['docs_health'], $facts['code_intelligence_ready']);

        $result = $this->gate->evaluate($this->candidate(), $facts);

        $this->assertSame(AtlasSelfConstructionScopeExpansionReadinessGate::STATUS_HOLD, $result['status']);
        $this->assertFalse($result['ready']);
        $combined = implode(' ', $result['hold_reasons']);
        $this->assertStringContainsString('optional_freshness_missing:', $combined);
    }

    public function test_ac3_explicit_false_optional_freshness_blocks(): void
    {
        $result = $this->gate->evaluate($this->candidate(), $this->fullFacts([
            'test_suite_green' => false,
        ]));

        $this->assertSame(AtlasSelfConstructionScopeExpansionReadinessGate::STATUS_BLOCKED, $result['status']);
        $this->assertContains('test_suite_green_not_ready', $result['blockers']);
    }

    public function test_ac3_false_proof_freshness_blocks_not_holds(): void
    {
        $result = $this->gate->evaluate($this->candidate(), $this->fullFacts([
            'context_pack_fresh'  => false,
            'rollback_evidence'   => false,
        ]));

        $this->assertSame(AtlasSelfConstructionScopeExpansionReadinessGate::STATUS_BLOCKED, $result['status']);
    }

    // ── AC4: fully proven → ready, sorted evidence_refs, deterministic hash

    public function test_ac4_fully_proven_returns_ready(): void
    {
        $result = $this->gate->evaluate($this->candidate(), $this->fullFacts());

        $this->assertSame(AtlasSelfConstructionScopeExpansionReadinessGate::STATUS_READY, $result['status']);
        $this->assertTrue($result['ready']);
        $this->assertSame([], $result['blockers']);
        $this->assertSame([], $result['hold_reasons']);
    }

    public function test_ac4_evidence_refs_are_sorted(): void
    {
        $result = $this->gate->evaluate($this->candidate(), $this->fullFacts([
            'evidence_refs' => ['z-ref', 'a-ref', 'm-ref'],
        ]));

        $refs = $result['evidence_refs'];
        $sorted = $refs;
        sort($sorted, SORT_STRING);
        $this->assertSame($sorted, $refs);
    }

    public function test_ac4_readiness_hash_is_deterministic(): void
    {
        $facts = $this->fullFacts();

        $this->assertSame(
            $this->gate->evaluate($this->candidate(), $facts)['readiness_hash'],
            $this->gate->evaluate($this->candidate(), $facts)['readiness_hash'],
        );
    }

    public function test_ac4_different_inputs_produce_different_hashes(): void
    {
        $hash1 = $this->gate->evaluate($this->candidate('scope-a'), $this->fullFacts())['readiness_hash'];
        $hash2 = $this->gate->evaluate($this->candidate('scope-b'), $this->fullFacts())['readiness_hash'];

        $this->assertNotSame($hash1, $hash2);
    }
}
