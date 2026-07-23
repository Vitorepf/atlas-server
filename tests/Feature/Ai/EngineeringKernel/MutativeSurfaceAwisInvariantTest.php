<?php

namespace Tests\Feature\Ai\EngineeringKernel;

use App\Services\Ai\EngineeringKernel\Coverage\EngineeringExecutionSurfaceRegistry;
use App\Services\Ai\ExecutionAuthority\AwisExecutionGatePort;
use App\Services\Ai\WorkspaceIntelligence\AtlasWorkspaceIntelligenceExecutionGateService;
use Tests\TestCase;

final class MutativeSurfaceAwisInvariantTest extends TestCase
{
    public function test_every_mutative_surface_declares_and_executes_awis_gate_fail_closed(): void
    {
        $surfaces = array_values(array_filter(
            EngineeringExecutionSurfaceRegistry::all(),
            static fn (array $surface): bool => ($surface['mutative'] ?? false) === true,
        ));

        $this->assertCount(6, $surfaces);

        foreach ($surfaces as $surface) {
            $this->assertSame(
                AtlasWorkspaceIntelligenceExecutionGateService::class,
                $surface['awis_gate_class'] ?? null,
                $surface['id'].' must declare the canonical AWIS execution gate.',
            );

            $gate = new class implements AwisExecutionGatePort
            {
                /** @var list<array{workspace:?string,mode:string,task:string}> */
                public array $calls = [];

                public function gate(?string $workspace = null, string $mode = 'conversation', string $task = '', array $conversationTexts = []): array
                {
                    $this->calls[] = ['workspace' => $workspace, 'mode' => $mode, 'task' => $task];

                    return [
                        'schema_version' => AtlasWorkspaceIntelligenceExecutionGateService::SCHEMA_VERSION,
                        'allowed' => false,
                        'status' => 'blocked',
                        'blockers' => ['workspace_not_certified'],
                    ];
                }
            };

            $probe = EngineeringExecutionSurfaceRegistry::probeAwisGateRefusal(
                (string) $surface['id'],
                $gate,
                '/tmp/not-certified',
            );

            $this->assertSame('refused', $probe['status'], $surface['id'].' must refuse an uncertified workspace.');
            $this->assertSame(['workspace_not_certified'], $probe['blockers']);
            $this->assertCount(1, $gate->calls, $surface['id'].' must invoke AWIS gate behaviorally.');
            $this->assertSame($surface['awis_mode'], $gate->calls[0]['mode']);
            $this->assertSame($surface['id'], $gate->calls[0]['task']);
        }
    }
}
