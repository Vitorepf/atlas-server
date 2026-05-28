<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Programming;

use App\Services\Ai\Programming\AtlasProgrammingOrchestrator;
use Tests\TestCase;

/**
 * Focused contract tests for AtlasProgrammingOrchestrator (factory-critical runtime).
 */
final class AtlasProgrammingOrchestratorTest extends TestCase
{
    public function test_focused_unit_test_path_is_same_name_coverage(): void
    {
        $this->assertSame(
            'tests/Unit/Ai/Programming/AtlasProgrammingOrchestratorTest.php',
            AtlasProgrammingOrchestrator::focusedUnitTestPath(),
        );
    }

    public function test_it_declares_factory_critical_programming_domain_contract(): void
    {
        $orchestrator = app(AtlasProgrammingOrchestrator::class);

        $this->assertSame('AtlasProgrammingOrchestrator', $orchestrator->orchestratorId());
        $this->assertSame(['programming'], $orchestrator->supportedDomains());
        $this->assertSame('implemented', $orchestrator->maturity());
        $this->assertEqualsCanonicalizing([
            'programming.dev',
            'programming.repair',
            'programming.review',
            'programming.refactor',
            'programming.qa',
            'programming.security',
            'programming.database',
            'programming.frontend',
            'programming.visual',
            'programming.forge',
        ], $orchestrator->supportedFlows());
    }

    public function test_plan_emits_canonical_flow_for_domain_sdk_consumers(): void
    {
        $orchestrator = app(AtlasProgrammingOrchestrator::class);

        $plan = $orchestrator->plan('programming.repair', [
            'task' => 'corrigir teste quebrado',
        ], [
            'workspace' => sys_get_temp_dir(),
            'interactive' => false,
        ]);

        $this->assertSame('planned', $plan['status']);
        $this->assertSame('programming', $plan['domain']);
        $this->assertSame('programming.repair', $plan['flow']);
        $this->assertSame('repair', $plan['programming_flow']);
        $this->assertSame('AtlasProgrammingOrchestrator', $plan['orchestrator']);
        $this->assertSame('programming.repair', data_get($plan, 'programming_orchestration_contract.programming_flow'));
    }

    public function test_execute_defers_to_runner_and_preserves_canonical_flow(): void
    {
        $orchestrator = app(AtlasProgrammingOrchestrator::class);

        $plan = $orchestrator->plan('programming.dev', [
            'task' => 'ajuste pequeno',
        ], [
            'workspace' => sys_get_temp_dir(),
            'interactive' => false,
        ]);

        $result = $orchestrator->execute($plan, [
            'surface_id' => 'phpunit',
        ]);

        $this->assertSame('requires_runner', $result['status']);
        $this->assertSame('programming.dev', $result['flow']);
        $this->assertSame('programming', $result['domain']);
        $this->assertSame('AtlasProgrammingOrchestrator', $result['orchestrator']);
        $this->assertSame(
            'programming_execution_is_dispatched_by_cli_or_engineering_harness',
            $result['reason'],
        );
        $this->assertSame($plan, $result['plan']);
    }

    public function test_summarize_projects_flow_plan_id_and_evidence_refs(): void
    {
        $orchestrator = app(AtlasProgrammingOrchestrator::class);

        $summary = $orchestrator->summarize([
            'status' => 'passed',
            'flow' => 'programming.repair',
            'plan_id' => 'plan-repair-1',
            'evidence_refs' => ['run:1', 'patch:1'],
        ], [
            'surface_id' => 'phpunit',
        ]);

        $this->assertSame('passed', $summary['status']);
        $this->assertSame('programming.repair', $summary['flow']);
        $this->assertSame('plan-repair-1', $summary['plan_id']);
        $this->assertSame(['run:1', 'patch:1'], $summary['evidence_refs']);
        $this->assertSame('AtlasProgrammingOrchestrator', $summary['orchestrator']);
    }
}
