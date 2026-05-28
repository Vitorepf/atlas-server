<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\AgentExecution;

use App\Services\Ai\SoftwareCompanyStewardship\AgentExecution\MultiAgentCycleCertificationService;
use Tests\TestCase;

/**
 * AP-800 — read-only multi-agent cycle certification. The future runtimes
 * (AP-795..AP-799) do not exist yet, so capability presence is supplied via
 * `capability_overrides`; absence is the real default and must never produce a
 * false production pass.
 */
final class MultiAgentCycleCertificationServiceTest extends TestCase
{
    private function service(): MultiAgentCycleCertificationService
    {
        return new MultiAgentCycleCertificationService;
    }

    /**
     * @param  array<string,mixed>  $overrides
     * @return array<string,mixed>
     */
    private function completeCycle(array $overrides = []): array
    {
        return array_replace_recursive([
            'multi_agent' => true,
            'claims_complete' => true,
            'scope_profile' => 'factory_max',
            'finding' => ['scope_profile' => 'factory_max', 'breadth' => 'broad', 'kind' => 'runtime'],
            'substrate_facts' => [
                'provider_invoked' => true,
                'provider_authority' => 'atlas_decide',
                'provider_calls' => 2,
                'sandbox_kind' => 'local_git_worktree',
                'worktree_materialized' => true,
                'owner_runtime_chain' => ['AP-747', 'AP-756', 'AP-757', 'AP-749', 'AP-758', 'AP-759', 'AP-750'],
                'product_diff_exists' => true,
                'focused_validation_ran' => true,
                'inbox_item_emitted' => true,
                'evidence_refs_present' => true,
                'merge_governor_evaluated' => true,
            ],
            'slice_plan' => ['decomposition_status' => 'sliced', 'slices' => [['slice_id' => 's1'], ['slice_id' => 's2']]],
            'lanes' => [
                'context_scout' => ['status' => 'completed'],
                'architect' => ['status' => 'completed'],
                'implementer' => ['status' => 'completed'],
                'reviewer' => ['status' => 'completed'],
                'judge' => ['status' => 'completed'],
            ],
            'judge_decision' => ['selected_candidate' => 'implementer_diff_1', 'rationale' => 'lowest risk, passes validation'],
            'focused_validation' => ['ran' => true, 'passed' => true],
            'merge_governance' => ['status' => 'merged', 'main_before' => 'aaaaaa', 'main_after' => 'bbbbbb', 'main_advanced' => true],
        ], $overrides);
    }

    /**
     * @return array<string,bool>
     */
    private function allCapabilities(): array
    {
        return [
            'provider_port_session_store' => true,
            'finding_slice_planner' => true,
            'lane_orchestrator' => true,
            'integration_judge' => true,
            'repair_planner' => true,
            'ap793_substrate_facts' => true,
            'ap792_harness' => true,
        ];
    }

    public function test_complete_fixture_passes_only_in_runtime_real(): void
    {
        $service = $this->service();

        // runtime_real: a complete real cycle with all capabilities -> production passed.
        $real = $service->certify([
            'use_real_services' => true,
            'real_recorded_cycle' => $this->completeCycle(),
            'capability_overrides' => $this->allCapabilities(),
        ]);
        $this->assertSame(MultiAgentCycleCertificationService::STATUS_PASSED, $real['status']);
        $this->assertTrue($real['production_certified']);
        $this->assertSame('runtime_real', $real['certification_mode']);
        $this->assertSame([], $real['blockers']);

        // Same fixture in test_mode -> contract self-test only, never production.
        $test = $service->certify([
            'cycle' => $this->completeCycle(),
            'capability_overrides' => $this->allCapabilities(),
        ]);
        $this->assertSame('test_mode', $test['certification_mode']);
        $this->assertFalse($test['production_certified']);
        $this->assertSame(MultiAgentCycleCertificationService::STATUS_PARTIAL, $test['status']);
    }

    public function test_missing_provider_port_is_partial(): void
    {
        $caps = $this->allCapabilities();
        $caps['provider_port_session_store'] = false;

        $report = $this->service()->certify([
            'use_real_services' => true,
            'real_recorded_cycle' => $this->completeCycle(),
            'capability_overrides' => $caps,
        ]);

        $this->assertSame(MultiAgentCycleCertificationService::STATUS_PARTIAL, $report['status']);
        $this->assertFalse($report['production_certified']);
        $this->assertContains('provider_port_session_store', $report['missing_capabilities']);
    }

    public function test_broad_factory_max_without_slice_plan_is_blocked(): void
    {
        $cycle = $this->completeCycle(['slice_plan' => []]);
        unset($cycle['slice_plan']);

        $report = $this->service()->certify([
            'use_real_services' => true,
            'real_recorded_cycle' => $cycle,
            'capability_overrides' => $this->allCapabilities(),
        ]);

        $this->assertSame(MultiAgentCycleCertificationService::STATUS_BLOCKED, $report['status']);
        $this->assertContains('factory_max_broad_finding_without_slice_plan', $report['blockers']);
        $this->assertFalse($report['production_certified']);
    }

    public function test_validation_failed_without_repair_planner_is_blocked(): void
    {
        // Narrow finding so the slice plan is not required; isolate the repair blocker.
        $cycle = $this->completeCycle([
            'finding' => ['scope_profile' => 'factory_max', 'breadth' => 'narrow', 'kind' => 'test'],
            'focused_validation' => ['ran' => true, 'passed' => false],
            'merge_governance' => ['status' => 'blocked', 'main_advanced' => false],
            'lanes' => [
                'context_scout' => ['status' => 'completed'],
                'architect' => ['status' => 'completed'],
                'implementer' => ['status' => 'completed'],
                'reviewer' => ['status' => 'completed'],
                'judge' => ['status' => 'completed'],
            ],
        ]);
        // No repair lane present.
        $caps = $this->allCapabilities();
        $caps['repair_planner'] = false;

        $report = $this->service()->certify([
            'use_real_services' => true,
            'real_recorded_cycle' => $cycle,
            'capability_overrides' => $caps,
        ]);

        $this->assertSame(MultiAgentCycleCertificationService::STATUS_BLOCKED, $report['status']);
        $this->assertContains('validation_failed_without_repair_lane', $report['blockers']);
    }

    public function test_validation_failed_with_repair_lane_is_not_blocked_on_repair(): void
    {
        $cycle = $this->completeCycle([
            'finding' => ['scope_profile' => 'factory_max', 'breadth' => 'narrow', 'kind' => 'test'],
            'focused_validation' => ['ran' => true, 'passed' => false],
            'lanes' => [
                'context_scout' => ['status' => 'completed'],
                'architect' => ['status' => 'completed'],
                'implementer' => ['status' => 'completed'],
                'reviewer' => ['status' => 'completed'],
                'judge' => ['status' => 'completed'],
                'repair' => ['status' => 'attempted'],
            ],
        ]);

        $report = $this->service()->certify([
            'use_real_services' => true,
            'real_recorded_cycle' => $cycle,
            'capability_overrides' => $this->allCapabilities(),
        ]);

        $this->assertNotContains('validation_failed_without_repair_lane', $report['blockers']);
        $this->assertTrue($report['repair']['present']);
        $this->assertTrue($report['repair']['required']);
    }

    public function test_test_mode_never_certifies_production(): void
    {
        // Even with everything present and the cycle claiming runtime_real,
        // test_mode (no use_real_services) can never reach production.
        $cycle = $this->completeCycle();
        $cycle['substrate_facts']['runtime_real'] = true; // fixture self-claim must be ignored

        $report = $this->service()->certify([
            'cycle' => $cycle,
            'capability_overrides' => $this->allCapabilities(),
        ]);

        $this->assertSame('test_mode', $report['certification_mode']);
        $this->assertFalse($report['production_certified']);
        $this->assertNotSame(MultiAgentCycleCertificationService::STATUS_PASSED, $report['status']);
        $this->assertFalse($report['claim_policy']['fixtures_certify_production']);
    }

    public function test_simulated_provider_never_reaches_production(): void
    {
        $cycle = $this->completeCycle();
        $cycle['substrate_facts']['provider_simulated'] = true;

        $report = $this->service()->certify([
            'use_real_services' => true,
            'real_recorded_cycle' => $cycle,
            'capability_overrides' => $this->allCapabilities(),
        ]);

        $this->assertFalse($report['provider_was_real']);
        $this->assertFalse($report['production_certified']);
    }

    public function test_product_mode_projection_contains_lanes_and_next_action(): void
    {
        $report = $this->service()->certify([
            'cycle' => $this->completeCycle(),
            'capability_overrides' => $this->allCapabilities(),
        ]);

        $projection = $report['product_mode_projection'];
        $this->assertSame(MultiAgentCycleCertificationService::PRODUCT_MODE_SCHEMA, $projection['schema_version']);
        $this->assertNotEmpty($projection['lanes']);
        $laneNames = array_map(static fn (array $l): string => (string) $l['lane'], $projection['lanes']);
        foreach (['context_scout', 'architect', 'implementer', 'reviewer', 'judge'] as $lane) {
            $this->assertContains($lane, $laneNames);
        }
        $this->assertArrayHasKey('next_operator_action', $projection);
        $this->assertNotSame('', trim((string) $projection['next_operator_action']));
        $this->assertArrayHasKey('slice_plan', $projection);
        $this->assertArrayHasKey('judge_decision', $projection);
        $this->assertArrayHasKey('repair', $projection);
    }

    public function test_capability_detection_blocks_false_pass_when_any_capability_absent(): void
    {
        // The AP-795..AP-799 runtimes are built concurrently by other agents, so
        // this test does not assume which classes exist. It proves the mechanism:
        // forcing any single required capability absent must drop production and
        // list it as missing — there is no false pass. ap792_harness is a real
        // class and must always be detected present.
        $caps = $this->allCapabilities();
        $caps['integration_judge'] = false;

        $report = $this->service()->certify([
            'use_real_services' => true,
            'real_recorded_cycle' => $this->completeCycle(),
            'capability_overrides' => $caps,
        ]);

        $this->assertFalse($report['production_certified']);
        $this->assertContains('integration_judge', $report['missing_capabilities']);
        $this->assertNotContains('ap792_harness', $report['missing_capabilities']);

        // And detection reflects real presence: with no overrides at all, the
        // ap792 harness class and the AP-793/794 contract docs are present.
        $live = $this->service()->certify([
            'use_real_services' => true,
            'real_recorded_cycle' => $this->completeCycle(),
        ]);
        $this->assertTrue($live['capabilities']['ap792_harness']['present']);
        $this->assertTrue($live['capabilities']['ap793_substrate_facts']['present']);
    }

    public function test_report_hash_is_deterministic(): void
    {
        $service = $this->service();
        $input = [
            'use_real_services' => true,
            'real_recorded_cycle' => $this->completeCycle(),
            'capability_overrides' => $this->allCapabilities(),
        ];

        $a = $service->certify($input);
        $b = $service->certify($input);

        $this->assertSame($a['report_hash'], $b['report_hash']);
        // generated_at is excluded from the hash, so it must not appear in both being equal.
        $this->assertArrayHasKey('generated_at', $a);
    }
}
