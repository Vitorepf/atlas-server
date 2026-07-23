<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\Readiness\AtlasSelfConstructionReadinessService;
use App\Services\Ai\SelfConstruction\Readiness\ReadinessProjectionAgentAutomaticDispatchBatch1Section;
use Tests\TestCase;

final class AtlasAiSelfConstructionReadinessProjectionAgentAutomaticDispatchBatch1SectionTest extends TestCase
{
    public function test_guarded_runtime_packet_declares_only_existing_allowed_files(): void
    {
        $runtime = app(AtlasSelfConstructionReadinessService::class);
        $section = (new ReadinessProjectionAgentAutomaticDispatchBatch1Section)->setMother($runtime);

        $direct = $section->agentAutomaticDispatchSchedulerOneShotTickGuardedRuntimeInvocationImplementationPacket();
        $viaFacade = $runtime->agentAutomaticDispatchSchedulerOneShotTickGuardedRuntimeInvocationImplementationPacket();
        $packet = (array) data_get(
            $direct,
            'agent_automatic_dispatch_scheduler_one_shot_tick_guarded_runtime_invocation_implementation_packet',
            []
        );

        $this->assertSame($direct, $viaFacade);
        $this->assertSame('ready_for_scoped_one_shot_tick_guarded_runtime_invocation_implementation', $packet['status']);
        $this->assertTrue($packet['implementation_policy']['implementation_allowed_by_packet']);

        foreach ($packet['allowed_files'] as $path) {
            $this->assertFileExists(base_path($path), "Implementation packet declares missing allowed file: {$path}");
        }
    }
}
