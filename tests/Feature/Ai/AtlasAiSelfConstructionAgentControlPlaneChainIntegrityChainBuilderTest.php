<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\AgentControlPlaneChainIntegrityChainBuilder;
use Tests\TestCase;

final class AtlasAiSelfConstructionAgentControlPlaneChainIntegrityChainBuilderTest extends TestCase
{
    public function test_canonical_deep_chain_returns_array(): void
    {
        $builder = new AgentControlPlaneChainIntegrityChainBuilder;

        $chain = $builder->canonicalDeepChain();

        $this->assertIsArray($chain);
    }

    public function test_deep_chain_entry_returns_string_keyed_array(): void
    {
        $builder = new AgentControlPlaneChainIntegrityChainBuilder;

        $entry = $builder->deepChainEntry('test_slice', 'test', 'Test\\Class', 'prepare', 'test bullet');

        $this->assertIsArray($entry);
    }

    public function test_strip_dispatch_prefix_returns_string(): void
    {
        $builder = new AgentControlPlaneChainIntegrityChainBuilder;

        $result = $builder->stripDispatchPrefix('any_string');

        $this->assertIsString($result);
    }

    public function test_cli_base_for_slice_returns_empty_for_empty(): void
    {
        $builder = new AgentControlPlaneChainIntegrityChainBuilder;

        $this->assertSame('', $builder->cliBaseForSlice(''));
    }

    public function test_cli_base_for_slice_transforms_underscores_to_dashes_with_agent_prefix(): void
    {
        $builder = new AgentControlPlaneChainIntegrityChainBuilder;

        $this->assertSame('agent-post-start-evidence-corridor', $builder->cliBaseForSlice('post_start_evidence_corridor'));
    }

    public function test_build_shallow_chain_returns_empty_for_empty_input(): void
    {
        $builder = new AgentControlPlaneChainIntegrityChainBuilder;

        $this->assertSame([], $builder->buildShallowChain([]));
    }

    public function test_build_shallow_chain_filters_non_dispatch_capabilities(): void
    {
        $builder = new AgentControlPlaneChainIntegrityChainBuilder;

        // Non-dispatch prefix — should not produce any candidate.
        $result = $builder->buildShallowChain(['some_other_capability_contract']);
        $this->assertSame([], $result);
    }

    public function test_build_shallow_chain_requires_full_quintet(): void
    {
        $builder = new AgentControlPlaneChainIntegrityChainBuilder;

        // Only _contract present — not a full quintet.
        $capability = 'automatic_dispatch_scheduler_one_shot_tick_post_start_provider_start_driver_gate_contract';
        $result = $builder->buildShallowChain([$capability]);
        $this->assertSame([], $result);
    }

    public function test_build_shallow_chain_accepts_full_quintet(): void
    {
        $builder = new AgentControlPlaneChainIntegrityChainBuilder;

        $base = 'automatic_dispatch_scheduler_one_shot_tick_post_start_provider_start_driver_gate';
        $capability = [
            $base.'_contract',
            $base.'_preflight',
            $base.'_implementation_packet',
            $base.'_invoker_service',
            $base.'_status_projection',
        ];
        $result = $builder->buildShallowChain($capability);
        $this->assertContains($base, $result);
    }
}