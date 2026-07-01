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

    public function test_no_gaps_returns_empty_chain(): void
    {
        $result = (new AtlasExternalBrainCapabilityGapTaskChainCompiler)->compile([]);

        $this->assertSame([], $result['chain']);
        $this->assertSame([], $result['gap_chains']);
        $this->assertSame('atlas.self_construction.external_brain.capability_gap_task_chain_compiler.v1', $result['schema']);
    }
}
