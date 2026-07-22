<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\Readiness;

use App\Services\Ai\SelfConstruction\Readiness\AtlasSelfConstructionReadinessService;
use App\Services\Ai\SelfConstruction\Readiness\ReadinessProjectionPostStartGateStatusSection;
use Tests\TestCase;

final class ReadinessProjectionPostStartGateStatusSectionTest extends TestCase
{
    public function test_bound_section_rejects_dynamic_calls_to_private_mother_methods(): void
    {
        $section = (new ReadinessProjectionPostStartGateStatusSection)
            ->setMother(app(AtlasSelfConstructionReadinessService::class));

        $this->expectException(\BadMethodCallException::class);

        $section->stableHash(['private' => 'mother-method']);
    }

    public function test_bound_section_executes_a_status_with_explicit_internal_dependencies(): void
    {
        $section = (new ReadinessProjectionPostStartGateStatusSection)
            ->setMother(app(AtlasSelfConstructionReadinessService::class));
        $status = $section->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartExecutorPlanGateStatus();
        $preflight = $section->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartStartExecutionGatePreflight();

        $this->assertArrayHasKey('schema_version', $status);
        $this->assertArrayHasKey('agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_executor_plan_gate_status_hash', $status);
        $this->assertArrayHasKey('schema_version', $preflight);
        $this->assertArrayHasKey('agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_start_execution_gate_preflight_hash', $preflight);
    }
}
