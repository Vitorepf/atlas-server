<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\Readiness\AtlasSelfConstructionReadinessService;
use App\Services\Ai\SelfConstruction\Readiness\ReadinessProjectionAgentAutomaticDispatchBatch2Section;
use Tests\TestCase;

final class AtlasAiSelfConstructionReadinessProjectionAgentAutomaticDispatchBatch2SectionTest extends TestCase
{
    public function test_all_eight_implementation_packets_declare_only_existing_allowed_files(): void
    {
        $runtime = app(AtlasSelfConstructionReadinessService::class);
        $section = (new ReadinessProjectionAgentAutomaticDispatchBatch2Section)->setMother($runtime);

        foreach (self::implementationPacketMethods() as $method) {
            $direct = $section->{$method}();
            $viaFacade = $runtime->{$method}();
            $packet = [];
            foreach ($direct as $key => $value) {
                if (str_ends_with($key, '_implementation_packet')) {
                    $packet = (array) $value;

                    break;
                }
            }

            $this->assertSame($direct, $viaFacade, $method);
            $this->assertStringStartsWith('ready_for_scoped_', (string) $packet['status'], $method);
            $this->assertTrue($packet['implementation_policy']['implementation_allowed_by_packet'], $method);

            foreach ($packet['allowed_files'] as $path) {
                $this->assertFileExists(base_path($path), "{$method} declares missing allowed file: {$path}");
            }
        }
    }

    /** @return list<string> */
    private static function implementationPacketMethods(): array
    {
        return [
            'agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartStartExecutionGateImplementationPacket',
            'agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartProcessStartEnvelopeGateImplementationPacket',
            'agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartActualProcessStartRehearsalGateImplementationPacket',
            'agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartFinalProcessStartAuthorizationGateImplementationPacket',
            'agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartGuardedProcessStartExecutorGateImplementationPacket',
            'agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartSupervisedStartActivationGateImplementationPacket',
            'agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerExecutorPlanImplementationPacket',
            'agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerExecutorFreshReleaseGateImplementationPacket',
        ];
    }
}
