<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainCapabilityGapTaskChainCompiler;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainCapabilityGapTaskChainCompilerTest extends TestCase
{
    public function test_context_precedes_gate_and_runtime_integration_depends_on_both(): void
    {
        $result = (new AtlasExternalBrainCapabilityGapTaskChainCompiler)->compile([
            'gaps' => [
                [
                    'gap_id' => 'gap-a',
                    'blockers' => [
                        ['type' => 'no_runtime_integration'],
                        ['type' => 'missing_context'],
                        ['type' => 'weak_gate'],
                    ],
                ],
            ],
        ]);

        $chainByType = [];
        foreach ($result['chain'] as $node) {
            $chainByType[$node['blocker_type']] = $node;
        }

        $this->assertSame([], $chainByType['missing_context']['dependency_ids']);
        $this->assertSame([$chainByType['missing_context']['task_id']], $chainByType['weak_gate']['dependency_ids']);
        $this->assertSame([$chainByType['weak_gate']['task_id']], $chainByType['no_runtime_integration']['dependency_ids']);

        $this->assertSame([
            $chainByType['missing_context']['task_id'],
            $chainByType['weak_gate']['task_id'],
            $chainByType['no_runtime_integration']['task_id'],
        ], $result['gap_chains']['gap-a']);
    }

    public function test_shared_unblocker_across_gaps_is_deduplicated(): void
    {
        $result = (new AtlasExternalBrainCapabilityGapTaskChainCompiler)->compile([
            'gaps' => [
                [
                    'gap_id' => 'gap-a',
                    'blockers' => [
                        ['type' => 'missing_context', 'unblocker_id' => 'shared-context-fix'],
                    ],
                ],
                [
                    'gap_id' => 'gap-b',
                    'blockers' => [
                        ['type' => 'missing_context', 'unblocker_id' => 'shared-context-fix'],
                    ],
                ],
            ],
        ]);

        $this->assertCount(1, $result['chain']);
        $sharedTaskId = $result['chain'][0]['task_id'];
        $this->assertSame([$sharedTaskId], $result['gap_chains']['gap-a']);
        $this->assertSame([$sharedTaskId], $result['gap_chains']['gap-b']);
        $this->assertSame(['gap-a', 'gap-b'], $result['chain'][0]['gap_ids']);
    }

    public function test_shared_unblocker_emits_dependent_gap_ids_proof_contracts_and_reuse_reason(): void
    {
        $result = (new AtlasExternalBrainCapabilityGapTaskChainCompiler)->compile([
            'gaps' => [
                [
                    'gap_id' => 'gap-a',
                    'blockers' => [
                        ['type' => 'missing_context', 'unblocker_id' => 'shared-context-fix', 'acceptance_strength' => 'a-strength'],
                    ],
                ],
                [
                    'gap_id' => 'gap-b',
                    'blockers' => [
                        ['type' => 'missing_context', 'unblocker_id' => 'shared-context-fix', 'acceptance_strength' => 'b-strength'],
                    ],
                ],
            ],
        ]);

        $node = $result['chain'][0];
        $this->assertSame(['gap-a', 'gap-b'], $node['dependent_gap_ids']);
        $this->assertArrayHasKey('gap-a', $node['proof_contracts_by_gap']);
        $this->assertArrayHasKey('gap-b', $node['proof_contracts_by_gap']);
        $this->assertSame('a-strength', $node['proof_contracts_by_gap']['gap-a']['acceptance_strength']);
        $this->assertSame('b-strength', $node['proof_contracts_by_gap']['gap-b']['acceptance_strength']);
        $this->assertSame('shared unblocker_id: shared-context-fix', $node['reuse_reason']);
    }

    public function test_non_shared_node_reuse_reason_is_null(): void
    {
        $result = (new AtlasExternalBrainCapabilityGapTaskChainCompiler)->compile([
            'gaps' => [
                ['gap_id' => 'gap-a', 'blockers' => [['type' => 'missing_context']]],
            ],
        ]);

        $this->assertNull($result['chain'][0]['reuse_reason']);
        $this->assertSame(['gap-a'], $result['chain'][0]['dependent_gap_ids']);
    }

    public function test_non_shared_node_gap_ids_contains_only_its_own_gap(): void
    {
        $result = (new AtlasExternalBrainCapabilityGapTaskChainCompiler)->compile([
            'gaps' => [
                [
                    'gap_id' => 'gap-a',
                    'blockers' => [
                        ['type' => 'weak_gate'],
                    ],
                ],
            ],
        ]);

        $this->assertSame(['gap-a'], $result['chain'][0]['gap_ids']);
    }

    public function test_every_node_carries_required_fields(): void
    {
        $result = (new AtlasExternalBrainCapabilityGapTaskChainCompiler)->compile([
            'gaps' => [
                [
                    'gap_id' => 'gap-a',
                    'blockers' => [
                        ['type' => 'weak_gate', 'allowed_files_hint' => ['app/Foo.php'], 'expected_delta' => 'proof strengthened', 'acceptance_strength' => 'strong'],
                    ],
                ],
            ],
        ]);

        $node = $result['chain'][0];
        $this->assertSame(['app/Foo.php'], $node['allowed_files_hint']);
        $this->assertSame('strong', $node['acceptance_strength']);
        $this->assertSame('proof strengthened', $node['expected_delta']);
        $this->assertArrayHasKey('dependency_ids', $node);
    }

    // ── AC1: every node carries a muscle_ready_spec_contract ──────────────────

    public function test_every_node_includes_muscle_ready_spec_contract(): void
    {
        $result = (new AtlasExternalBrainCapabilityGapTaskChainCompiler)->compile([
            'gaps' => [
                [
                    'gap_id' => 'gap-a',
                    'blockers' => [
                        ['type' => 'weak_gate', 'allowed_files_hint' => ['app/Foo.php'], 'acceptance_strength' => 'strong', 'expected_delta' => 'hardened'],
                    ],
                ],
            ],
        ]);

        $contract = $result['chain'][0]['muscle_ready_spec_contract'];
        foreach (['objective_seed', 'runnable_acceptance_seed', 'required_evidence', 'dependency_ids'] as $key) {
            $this->assertArrayHasKey($key, $contract, "Missing key: {$key}");
        }
        $this->assertSame('hardened', $contract['objective_seed']);
        $this->assertSame('strong', $contract['runnable_acceptance_seed']);
        $this->assertNotEmpty($contract['required_evidence']);
        $this->assertSame(['app/Foo.php'], $contract['allowed_files_hint']);
    }

    // ── AC2: shared unblocker merges dependent gap ids, preserves per-gap proof ─

    public function test_shared_unblocker_spec_contract_preserves_per_gap_proof_contracts(): void
    {
        $result = (new AtlasExternalBrainCapabilityGapTaskChainCompiler)->compile([
            'gaps' => [
                [
                    'gap_id' => 'gap-a',
                    'blockers' => [
                        ['type' => 'missing_context', 'unblocker_id' => 'shared-fix', 'acceptance_strength' => 'a-strength', 'allowed_files_hint' => ['app/A.php']],
                    ],
                ],
                [
                    'gap_id' => 'gap-b',
                    'blockers' => [
                        ['type' => 'missing_context', 'unblocker_id' => 'shared-fix', 'acceptance_strength' => 'b-strength', 'allowed_files_hint' => ['app/A.php']],
                    ],
                ],
            ],
        ]);

        $node = $result['chain'][0];
        $this->assertSame(['gap-a', 'gap-b'], $node['dependent_gap_ids']);

        $contract = $node['muscle_ready_spec_contract'];
        $this->assertArrayHasKey('gap-a', $contract['per_gap_proof_contracts']);
        $this->assertArrayHasKey('gap-b', $contract['per_gap_proof_contracts']);
        $this->assertSame('a-strength', $contract['per_gap_proof_contracts']['gap-a']['acceptance_strength']);
        $this->assertSame('b-strength', $contract['per_gap_proof_contracts']['gap-b']['acceptance_strength']);
    }

    // ── AC3: nodes without allowed_files_hint are marked not_muscle_ready ──────

    public function test_node_without_allowed_files_hint_is_marked_not_muscle_ready(): void
    {
        $result = (new AtlasExternalBrainCapabilityGapTaskChainCompiler)->compile([
            'gaps' => [
                ['gap_id' => 'gap-a', 'blockers' => [['type' => 'missing_context']]],
            ],
        ]);

        $this->assertTrue($result['chain'][0]['muscle_ready_spec_contract']['not_muscle_ready']);
    }

    public function test_node_with_allowed_files_hint_is_not_marked_not_muscle_ready(): void
    {
        $result = (new AtlasExternalBrainCapabilityGapTaskChainCompiler)->compile([
            'gaps' => [
                ['gap_id' => 'gap-a', 'blockers' => [['type' => 'missing_context', 'allowed_files_hint' => ['app/Foo.php']]]],
            ],
        ]);

        $this->assertFalse($result['chain'][0]['muscle_ready_spec_contract']['not_muscle_ready']);
    }

    public function test_no_gaps_returns_empty_chain(): void
    {
        $result = (new AtlasExternalBrainCapabilityGapTaskChainCompiler)->compile([]);

        $this->assertSame([], $result['chain']);
        $this->assertSame([], $result['gap_chains']);
        $this->assertSame('atlas.self_construction.external_brain.capability_gap_task_chain_compiler.v1', $result['schema']);
    }

    // ── AC: missing_context has no dependency_ids, weak_gate depends on context, runtime depends on weak_gate ─

    public function test_missing_context_has_empty_dependency_ids(): void
    {
        $result = (new AtlasExternalBrainCapabilityGapTaskChainCompiler)->compile([
            'gaps' => [
                ['gap_id' => 'gap-a', 'blockers' => [
                    ['type' => 'missing_context'],
                    ['type' => 'weak_gate'],
                    ['type' => 'no_runtime_integration'],
                ]],
            ],
        ]);

        $contextNode = $result['chain'][0];
        $this->assertSame('missing_context', $contextNode['blocker_type']);
        $this->assertSame([], $contextNode['dependency_ids']);
    }

    public function test_weak_gate_depends_on_context_task(): void
    {
        $result = (new AtlasExternalBrainCapabilityGapTaskChainCompiler)->compile([
            'gaps' => [
                ['gap_id' => 'gap-a', 'blockers' => [
                    ['type' => 'missing_context'],
                    ['type' => 'weak_gate'],
                    ['type' => 'no_runtime_integration'],
                ]],
            ],
        ]);

        $contextId = $result['chain'][0]['task_id'];
        $weakGateNode = $result['chain'][1];

        $this->assertSame('weak_gate', $weakGateNode['blocker_type']);
        $this->assertSame([$contextId], $weakGateNode['dependency_ids']);
    }

    public function test_no_runtime_integration_depends_on_weak_gate_task(): void
    {
        $result = (new AtlasExternalBrainCapabilityGapTaskChainCompiler)->compile([
            'gaps' => [
                ['gap_id' => 'gap-a', 'blockers' => [
                    ['type' => 'missing_context'],
                    ['type' => 'weak_gate'],
                    ['type' => 'no_runtime_integration'],
                ]],
            ],
        ]);

        $weakGateId = $result['chain'][1]['task_id'];
        $runtimeNode = $result['chain'][2];

        $this->assertSame('no_runtime_integration', $runtimeNode['blocker_type']);
        $this->assertSame([$weakGateId], $runtimeNode['dependency_ids']);
    }

    // ── AC: shared unblockers emitted once and referenced by each gap_chain ──

    public function test_shared_unblocker_emitted_once_in_chain(): void
    {
        $result = (new AtlasExternalBrainCapabilityGapTaskChainCompiler)->compile([
            'gaps' => [
                ['gap_id' => 'gap-a', 'blockers' => [
                    ['type' => 'missing_context', 'unblocker_id' => 'shared-fix'],
                ]],
                ['gap_id' => 'gap-b', 'blockers' => [
                    ['type' => 'missing_context', 'unblocker_id' => 'shared-fix'],
                ]],
                ['gap_id' => 'gap-c', 'blockers' => [
                    ['type' => 'missing_context', 'unblocker_id' => 'shared-fix'],
                ]],
            ],
        ]);

        $this->assertCount(1, $result['chain']);
        $sharedTaskId = $result['chain'][0]['task_id'];
        $this->assertSame([$sharedTaskId], $result['gap_chains']['gap-a']);
        $this->assertSame([$sharedTaskId], $result['gap_chains']['gap-b']);
        $this->assertSame([$sharedTaskId], $result['gap_chains']['gap-c']);
    }

    public function test_shared_unblocker_node_exposes_dependent_gap_ids(): void
    {
        $result = (new AtlasExternalBrainCapabilityGapTaskChainCompiler)->compile([
            'gaps' => [
                ['gap_id' => 'gap-a', 'blockers' => [
                    ['type' => 'weak_gate', 'unblocker_id' => 'shared-gate-fix'],
                ]],
                ['gap_id' => 'gap-b', 'blockers' => [
                    ['type' => 'weak_gate', 'unblocker_id' => 'shared-gate-fix'],
                ]],
            ],
        ]);

        $node = $result['chain'][0];
        $this->assertSame(['gap-a', 'gap-b'], $node['dependent_gap_ids']);
    }

    public function test_shared_unblocker_node_exposes_proof_contracts_by_gap(): void
    {
        $result = (new AtlasExternalBrainCapabilityGapTaskChainCompiler)->compile([
            'gaps' => [
                ['gap_id' => 'gap-a', 'blockers' => [
                    ['type' => 'weak_gate', 'unblocker_id' => 'shared-gate-fix', 'acceptance_strength' => 'a-strong'],
                ]],
                ['gap_id' => 'gap-b', 'blockers' => [
                    ['type' => 'weak_gate', 'unblocker_id' => 'shared-gate-fix', 'acceptance_strength' => 'b-strong'],
                ]],
            ],
        ]);

        $node = $result['chain'][0];
        $this->assertArrayHasKey('gap-a', $node['proof_contracts_by_gap']);
        $this->assertArrayHasKey('gap-b', $node['proof_contracts_by_gap']);
    }

    public function test_shared_unblocker_node_exposes_reuse_reason(): void
    {
        $result = (new AtlasExternalBrainCapabilityGapTaskChainCompiler)->compile([
            'gaps' => [
                ['gap_id' => 'gap-a', 'blockers' => [
                    ['type' => 'no_runtime_integration', 'unblocker_id' => 'shared-runtime-fix'],
                ]],
                ['gap_id' => 'gap-b', 'blockers' => [
                    ['type' => 'no_runtime_integration', 'unblocker_id' => 'shared-runtime-fix'],
                ]],
            ],
        ]);

        $node = $result['chain'][0];
        $this->assertSame('shared unblocker_id: shared-runtime-fix', $node['reuse_reason']);
    }

    public function test_compile_is_deterministic(): void
    {
        $facts = [
            'gaps' => [
                ['gap_id' => 'gap-a', 'blockers' => [
                    ['type' => 'missing_context'],
                    ['type' => 'weak_gate'],
                    ['type' => 'no_runtime_integration'],
                ]],
            ],
        ];
        $a = (new AtlasExternalBrainCapabilityGapTaskChainCompiler)->compile($facts);
        $b = (new AtlasExternalBrainCapabilityGapTaskChainCompiler)->compile($facts);

        $this->assertSame(json_encode($a), json_encode($b));
    }

    // ── AC: unlocks exposes what each task unlocks next ──────────────────────

    public function test_unlocks_field_exposes_what_each_task_unlocks_next(): void
    {
        $result = (new AtlasExternalBrainCapabilityGapTaskChainCompiler)->compile([
            'gaps' => [
                ['gap_id' => 'gap-a', 'blockers' => [
                    ['type' => 'missing_context', 'allowed_files_hint' => ['app/A.php']],
                    ['type' => 'weak_gate', 'allowed_files_hint' => ['app/B.php']],
                    ['type' => 'no_runtime_integration', 'allowed_files_hint' => ['app/C.php']],
                ]],
            ],
        ]);

        $context = $result['chain'][0];
        $weakGate = $result['chain'][1];
        $runtime = $result['chain'][2];

        // context unlocks weak_gate
        $this->assertSame([$weakGate['task_id']], $context['unlocks']);
        // weak_gate unlocks runtime
        $this->assertSame([$runtime['task_id']], $weakGate['unlocks']);
        // runtime unlocks nothing
        $this->assertSame([], $runtime['unlocks']);
    }

    // ── AC: orphan tasks marked not_ready ───────────────────────────────────

    public function test_single_isolated_task_marked_not_ready_as_orphan(): void
    {
        $result = (new AtlasExternalBrainCapabilityGapTaskChainCompiler)->compile([
            'gaps' => [
                ['gap_id' => 'gap-a', 'blockers' => [
                    ['type' => 'missing_context', 'allowed_files_hint' => ['app/A.php']],
                ]],
            ],
        ]);

        $node = $result['chain'][0];
        $this->assertTrue($node['not_ready']);
        $this->assertSame('orphan_task', $node['not_ready_reason']);
    }

    public function test_multi_task_chain_not_marked_orphan(): void
    {
        $result = (new AtlasExternalBrainCapabilityGapTaskChainCompiler)->compile([
            'gaps' => [
                ['gap_id' => 'gap-a', 'blockers' => [
                    ['type' => 'missing_context', 'allowed_files_hint' => ['app/A.php']],
                    ['type' => 'weak_gate', 'allowed_files_hint' => ['app/B.php']],
                ]],
            ],
        ]);

        $this->assertFalse($result['chain'][0]['not_ready']);
        $this->assertFalse($result['chain'][1]['not_ready']);
    }

    // ── AC: chain_value_score ────────────────────────────────────────────────

    public function test_chain_value_score_is_zero_for_empty_chain(): void
    {
        $result = (new AtlasExternalBrainCapabilityGapTaskChainCompiler)->compile([]);

        $this->assertSame(0.0, $result['chain_value_score']);
    }

    public function test_chain_value_score_is_one_when_all_ready_with_files(): void
    {
        $result = (new AtlasExternalBrainCapabilityGapTaskChainCompiler)->compile([
            'gaps' => [
                ['gap_id' => 'gap-a', 'blockers' => [
                    ['type' => 'missing_context', 'allowed_files_hint' => ['app/A.php']],
                    ['type' => 'weak_gate', 'allowed_files_hint' => ['app/B.php']],
                    ['type' => 'no_runtime_integration', 'allowed_files_hint' => ['app/C.php']],
                ]],
            ],
        ]);

        $this->assertSame(1.0, $result['chain_value_score']);
    }

    public function test_chain_value_score_below_one_when_orphan_present(): void
    {
        $result = (new AtlasExternalBrainCapabilityGapTaskChainCompiler)->compile([
            'gaps' => [
                ['gap_id' => 'gap-a', 'blockers' => [
                    ['type' => 'missing_context', 'allowed_files_hint' => ['app/A.php']],
                ]],
            ],
        ]);

        $this->assertLessThan(1.0, $result['chain_value_score']);
    }

    public function test_chain_value_score_below_one_when_no_allowed_files(): void
    {
        $result = (new AtlasExternalBrainCapabilityGapTaskChainCompiler)->compile([
            'gaps' => [
                ['gap_id' => 'gap-a', 'blockers' => [
                    ['type' => 'missing_context'],
                    ['type' => 'weak_gate'],
                ]],
            ],
        ]);

        $this->assertLessThan(1.0, $result['chain_value_score']);
    }

    // ── AC: tests cover at least three capability gaps ───────────────────────

    public function test_three_gaps_compile_into_ordered_chains(): void
    {
        $result = (new AtlasExternalBrainCapabilityGapTaskChainCompiler)->compile([
            'gaps' => [
                ['gap_id' => 'gap-a', 'blockers' => [
                    ['type' => 'missing_context', 'allowed_files_hint' => ['app/A.php']],
                    ['type' => 'weak_gate', 'allowed_files_hint' => ['app/B.php']],
                ]],
                ['gap_id' => 'gap-b', 'blockers' => [
                    ['type' => 'missing_context', 'allowed_files_hint' => ['app/C.php']],
                    ['type' => 'no_runtime_integration', 'allowed_files_hint' => ['app/D.php']],
                ]],
                ['gap_id' => 'gap-c', 'blockers' => [
                    ['type' => 'weak_gate', 'allowed_files_hint' => ['app/E.php']],
                    ['type' => 'no_runtime_integration', 'allowed_files_hint' => ['app/F.php']],
                ]],
            ],
        ]);

        $this->assertCount(3, $result['gap_chains']);
        $this->assertArrayHasKey('gap-a', $result['gap_chains']);
        $this->assertArrayHasKey('gap-b', $result['gap_chains']);
        $this->assertArrayHasKey('gap-c', $result['gap_chains']);
        $this->assertGreaterThan(0.0, $result['chain_value_score']);
    }
}
