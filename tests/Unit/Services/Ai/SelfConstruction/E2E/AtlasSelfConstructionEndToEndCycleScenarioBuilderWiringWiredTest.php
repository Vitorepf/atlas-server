<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Ai\SelfConstruction\E2E;

use App\Services\Ai\SelfConstruction\E2E\AtlasSelfConstructionEndToEndCycleScenarioBuilder;
use App\Services\Ai\SelfConstruction\E2E\AtlasSelfConstructionStewardshipInstanceRuntimePlan;
use Tests\TestCase;

final class AtlasSelfConstructionEndToEndCycleScenarioBuilderWiringWiredTest extends TestCase
{
    private function admittedLane(): array
    {
        return [
            'admitted' => true,
            'project_id' => 'atlas-self-construction',
            'repo_root' => '/repo',
            'mainline_branch' => 'main',
            'allowed_scope_roots' => ['/repo/app'],
            'forbidden_paths' => [],
            'verification_commands' => ['./vendor/bin/phpunit'],
            'merge_policy' => ['mode' => 'shared_main_with_scope_lock'],
            'knowledge_sync_policy' => ['targets' => ['docs', 'code_index']],
        ];
    }

    private function fullyCoveredCoverage(): array
    {
        return ['fully_covered' => true, 'missing_organ' => []];
    }

    private function organFactsForReady(): array
    {
        $facts = [];
        foreach (AtlasSelfConstructionEndToEndCycleScenarioBuilder::STEP_ORDER as $organ) {
            $facts[$organ] = ['present' => true];
        }
        $facts['cortex']['autonomy_owner'] = AtlasSelfConstructionEndToEndCycleScenarioBuilder::REQUIRED_AUTONOMY_OWNER;

        return $facts;
    }

    public function test_compose_with_e2e_scenario_attaches_ready_envelope_from_builder(): void
    {
        $plan = new AtlasSelfConstructionStewardshipInstanceRuntimePlan();
        $envelope = $plan->composeWithE2eScenario($this->admittedLane(), $this->fullyCoveredCoverage(), $this->organFactsForReady());

        $this->assertFalse($envelope['refused']);
        $this->assertArrayHasKey('e2e_cycle_scenario', $envelope);
        $scenario = $envelope['e2e_cycle_scenario'];
        $this->assertSame(AtlasSelfConstructionEndToEndCycleScenarioBuilder::SCHEMA, $scenario['schema']);
        $this->assertSame(AtlasSelfConstructionEndToEndCycleScenarioBuilder::STATUS_READY, $scenario['status']);
        $this->assertSame([], $scenario['blockers']);
        $this->assertCount(count(AtlasSelfConstructionEndToEndCycleScenarioBuilder::STEP_ORDER), $scenario['steps']);
    }

    public function test_compose_with_e2e_scenario_surfaces_blocked_when_organ_facts_missing(): void
    {
        $plan = new AtlasSelfConstructionStewardshipInstanceRuntimePlan();
        $envelope = $plan->composeWithE2eScenario($this->admittedLane(), $this->fullyCoveredCoverage(), []);

        $scenario = $envelope['e2e_cycle_scenario'];
        $this->assertSame(AtlasSelfConstructionEndToEndCycleScenarioBuilder::STATUS_BLOCKED, $scenario['status']);
        $this->assertNotEmpty($scenario['blockers']);
    }

    public function test_compose_with_e2e_scenario_uses_injected_builder_when_provided(): void
    {
        $plan = new AtlasSelfConstructionStewardshipInstanceRuntimePlan();
        $injected = new AtlasSelfConstructionEndToEndCycleScenarioBuilder();
        $envelope = $plan->composeWithE2eScenario($this->admittedLane(), $this->fullyCoveredCoverage(), $this->organFactsForReady(), $injected);
        $this->assertSame(AtlasSelfConstructionEndToEndCycleScenarioBuilder::SCHEMA, $envelope['e2e_cycle_scenario']['schema']);
    }
}
