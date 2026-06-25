<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\AgentControlPlaneTaskPacketBuilder;
use Tests\TestCase;

final class AgentControlPlaneTaskPacketBuilderSimplicityContractTest extends TestCase
{
    public function test_default_contract_has_canonical_atlas_native_ownership(): void
    {
        $contract = AgentControlPlaneTaskPacketBuilder::defaultSimplicityContract();

        $this->assertSame('atlas_native', $contract['final_runtime_owner']);
        $this->assertSame('atlas_server', $contract['steady_state_runtime_owner']);
        $this->assertSame('shared_local_main_with_allowed_files', $contract['default_execution_topology']);
        $this->assertFalse($contract['default_worktree_or_sandbox']);
        $this->assertSame('bootstrap_or_replaceable_muscle_only', $contract['external_worker_role']);

        foreach ([
            'human_or_external_provider_dependency_allowed',
            'operator_dependency_allowed',
            'human_dependency_allowed',
            'external_provider_dependency_allowed',
            'steady_state_requires_operator',
            'steady_state_requires_human',
            'steady_state_requires_external_provider',
        ] as $nonAtlasFlag) {
            $this->assertFalse($contract[$nonAtlasFlag], "{$nonAtlasFlag} must be false");
        }
    }

    public function test_built_packet_uses_shared_default_contract(): void
    {
        $builder = new AgentControlPlaneTaskPacketBuilder;
        $packet = $builder->build([
            'task_packet_id' => 'unit-test-packet',
            'objective' => 'noop',
            'allowed_files' => ['app/Foo.php'],
        ]);

        $this->assertSame(
            AgentControlPlaneTaskPacketBuilder::defaultSimplicityContract(),
            $packet['simplicity_contract'],
        );
    }
}
