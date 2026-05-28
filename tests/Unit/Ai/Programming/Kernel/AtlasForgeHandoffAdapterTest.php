<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Programming\Kernel;

use App\Models\AiDomainHandoff;
use App\Models\AiMission;
use App\Services\Ai\Programming\Kernel\AtlasForgeHandoffAdapter;
use PHPUnit\Framework\TestCase;

/**
 * Focused unit coverage for the factory-critical forge handoff adapter.
 *
 * Feature suites exercise promoteWithPacket end-to-end; this file guards
 * shouldPromote decision logic and the legacy promote() delegation without
 * spinning up Domain Runtime tables.
 */
final class AtlasForgeHandoffAdapterTest extends TestCase
{
    public function test_should_promote_returns_scope_too_large_for_obra_missions(): void
    {
        $adapter = $this->makeAdapter();

        $mission = new AiMission([
            'mission_type' => 'obra',
            'raw_prompt' => 'small tweak',
            'title' => 'Small tweak',
        ]);

        $this->assertSame('scope_too_large', $adapter->shouldPromote($mission));
    }

    public function test_should_promote_returns_null_when_no_escalation_signals(): void
    {
        $adapter = $this->makeAdapter();

        $mission = new AiMission([
            'mission_type' => 'dev',
            'raw_prompt' => 'fix typo in readme',
            'title' => 'Fix typo',
        ]);

        $this->assertNull($adapter->shouldPromote($mission));
    }

    public function test_should_promote_uses_title_when_raw_prompt_is_empty(): void
    {
        $adapter = $this->makeAdapter();

        $mission = new AiMission([
            'mission_type' => 'dev',
            'raw_prompt' => '',
            'title' => 'Planejar spec-driven design para billing multi-modulo',
        ]);

        $this->assertSame('sdd_required', $adapter->shouldPromote($mission));
    }

    public function test_should_promote_detects_forge_keywords_in_raw_prompt(): void
    {
        $adapter = $this->makeAdapter();

        $mission = new AiMission([
            'mission_type' => 'dev',
            'raw_prompt' => 'Refatorar auth multi-modulo com sdd',
            'title' => 'Auth refactor',
        ]);

        $this->assertSame('sdd_required', $adapter->shouldPromote($mission));
    }

    public function test_promote_delegates_to_promote_with_packet_handoff(): void
    {
        $handoff = new AiDomainHandoff(['receipt_hash' => str_repeat('a', 64)]);

        $adapter = $this->getMockBuilder(AtlasForgeHandoffAdapter::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['promoteWithPacket'])
            ->getMock();

        $mission = new AiMission(['id' => 'mission-1']);
        $workOrder = new \App\Models\AiWorkOrder(['id' => 'wo-1']);

        $adapter->expects($this->once())
            ->method('promoteWithPacket')
            ->with($mission, $workOrder, 'scope_too_large', ['workspace' => 'atlas-server'])
            ->willReturn([
                'handoff' => $handoff,
                'escalation_packet_v1' => null,
                'route_decision_v1' => ['recorded' => false],
            ]);

        $this->assertSame($handoff, $adapter->promote($mission, $workOrder, 'scope_too_large', [
            'workspace' => 'atlas-server',
        ]));
    }

    private function makeAdapter(): AtlasForgeHandoffAdapter
    {
        return new AtlasForgeHandoffAdapter(
            manifests: $this->createMock(\App\Services\Ai\DomainRuntime\DomainManifestRegistryService::class),
            handoffs: $this->createMock(\App\Services\Ai\DomainRuntime\DomainHandoffService::class),
            lifecycle: $this->createMock(\App\Services\Ai\Mission\MissionLifecycleService::class),
            seeder: $this->createMock(\App\Services\Ai\Programming\Kernel\ProgrammingDomainManifestSeeder::class),
            routeDecisions: $this->createMock(\App\Services\Ai\DualCore\DualCoreRouteDecisionService::class),
        );
    }
}
