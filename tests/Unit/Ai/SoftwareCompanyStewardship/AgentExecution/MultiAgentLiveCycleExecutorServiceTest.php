<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\AgentExecution;

use App\Services\Ai\SoftwareCompanyStewardship\AgentExecution\AgentExecutionProviderPortService;
use App\Services\Ai\SoftwareCompanyStewardship\AgentExecution\AgentExecutionSessionStoreService;
use App\Services\Ai\SoftwareCompanyStewardship\AgentExecution\MultiAgentCycleCertificationService;
use App\Services\Ai\SoftwareCompanyStewardship\AgentExecution\MultiAgentIntegrationJudgeService;
use App\Services\Ai\SoftwareCompanyStewardship\AgentExecution\MultiAgentLaneOrchestratorService;
use App\Services\Ai\SoftwareCompanyStewardship\AgentExecution\MultiAgentLiveCycleExecutorService;
use App\Services\Ai\SoftwareCompanyStewardship\AgentExecution\MultiAgentRepairPlannerService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\FindingSlicePlannerService;
use Tests\TestCase;

/**
 * AP-801 · Multi-agent live cycle executor tests. The executor composes the
 * AP-795..AP-800 services into one governed workcell. It NEVER invokes a
 * provider: a real (or injected fixture) owner-runtime result is consumed.
 * Storage is redirected to a temp dir; no real provider, branch or merge occurs.
 */
class MultiAgentLiveCycleExecutorServiceTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmp = sys_get_temp_dir().'/atlas_ap801_'.uniqid('', true);
        @mkdir($this->tmp, 0775, true);
    }

    protected function tearDown(): void
    {
        foreach ((array) glob($this->tmp.'/*') as $f) {
            is_file((string) $f) ? @unlink((string) $f) : null;
        }
        @rmdir($this->tmp);
        parent::tearDown();
    }

    private function executor(): MultiAgentLiveCycleExecutorService
    {
        $svc = new MultiAgentLiveCycleExecutorService(
            new FindingSlicePlannerService(),
            new MultiAgentLaneOrchestratorService(),
            new AgentExecutionProviderPortService(),
            new AgentExecutionSessionStoreService(new AgentExecutionProviderPortService()),
            new MultiAgentIntegrationJudgeService(),
            new MultiAgentRepairPlannerService(),
            new MultiAgentCycleCertificationService(),
        );
        $svc->setStorageRootForTesting($this->tmp);

        return $svc;
    }

    private function sessionStore(): AgentExecutionSessionStoreService
    {
        $store = new AgentExecutionSessionStoreService(new AgentExecutionProviderPortService());
        $store->setStorageRootForTesting($this->tmp);

        return $store;
    }

    /**
     * @return array<string,mixed>
     */
    private function slice(): array
    {
        return [
            'slice_id' => 'slc_test',
            'sequence' => 1,
            'owner' => 'atlas_dev',
            'risk_level' => 'low',
            'objective' => 'Add the missing focused test for FooService',
            'allowed_files' => ['tests/Unit/FooServiceTest.php'],
            'forbidden_files' => ['.env'],
            'expected_diff_shape' => 'test_only',
            'validation_commands' => ['php artisan test tests/Unit/FooServiceTest.php'],
            'evidence_obligations' => ['test_results'],
            'merge_policy' => 'auto_merge_eligible',
            'max_runtime_seconds' => 600,
            'retry_policy' => ['max_attempts' => 1],
        ];
    }

    /**
     * @param  array<string,mixed>  $overrides
     * @return array<string,mixed>
     */
    private function ownerRuntimeReal(array $overrides = []): array
    {
        return array_merge([
            'provider' => 'cursor_cli',
            'model' => 'composer-2.5-fast',
            'provider_called' => true,
            'provider_authority' => 'atlas_decide',
            'auth_mode' => 'local_account',
            'changed_files' => ['tests/Unit/FooServiceTest.php'],
            'diff_shape' => 'test_only',
            'validation' => ['ran' => true, 'passed' => true, 'commands' => ['php artisan test tests/Unit/FooServiceTest.php']],
            'worktree_path' => '/tmp/wt-ap801',
            'inbox_item_id' => 'inbox-1',
            'evidence_refs' => ['test_results'],
            'owner_runtime_chain' => 'AP-747->AP-750',
            'merge_governance' => ['status' => 'review_required'],
        ], $overrides);
    }

    public function test_happy_path_with_injected_owner_runtime_is_accepted_and_provider_real(): void
    {
        $r = $this->executor()->execute([
            'execute' => true,
            'finding' => ['finding_id' => 'f1', 'kind' => 'test', 'title' => 'missing test'],
            'executable_slice' => $this->slice(),
            'owner_runtime_result' => $this->ownerRuntimeReal(),
            'session_id' => 'sess-happy',
            'cycle_id' => 'cyc-happy',
        ]);

        $this->assertSame(MultiAgentLiveCycleExecutorService::STATUS_ACCEPTED_PENDING_MERGE, $r['status']);
        $this->assertTrue($r['provider_invoked']);
        $this->assertSame(5, $r['lane_count']);
        $this->assertSame(MultiAgentIntegrationJudgeService::STATUS_ACCEPTED, $r['judge_decision']['status']);
        $this->assertTrue($r['judge_decision']['all_gates_passed']);
        $this->assertTrue($r['merge_eligible']);
        $this->assertNotSame('', $r['write_lane']);
        $this->assertSame([], $r['blockers']);

        $roles = array_map(static fn (array $s): string => $s['role'], $r['lane_sessions']);
        $this->assertSame(['context_scout', 'architect', 'implementer', 'reviewer', 'judge'], $roles);
    }

    public function test_provider_unavailable_in_execute_mode_blocks_not_success(): void
    {
        $r = $this->executor()->execute([
            'execute' => true,
            'finding' => ['finding_id' => 'f1', 'kind' => 'test'],
            'executable_slice' => $this->slice(),
            // no owner_runtime_result -> the provider/owner runtime did not run.
            'session_id' => 'sess-x',
            'cycle_id' => 'cyc-x',
        ]);

        $this->assertSame(MultiAgentLiveCycleExecutorService::STATUS_BLOCKED, $r['status']);
        $this->assertFalse($r['provider_invoked']);
        $this->assertContains(MultiAgentLiveCycleExecutorService::BLOCKER_OWNER_RUNTIME_UNAVAILABLE, $r['blockers']);
        $this->assertFalse($r['merge_eligible']);
        $this->assertFalse($r['production_certified']);
    }

    public function test_broad_factory_max_finding_without_executable_slice_blocks(): void
    {
        $r = $this->executor()->execute([
            'execute' => true,
            'scope_profile' => 'factory_max',
            'finding' => [
                'finding_id' => 'big',
                'kind' => 'improvement',
                'title' => 'make Atlas better',
                'breadth' => 'broad',
            ],
            'session_id' => 'sess-broad',
            'cycle_id' => 'cyc-broad',
        ]);

        $this->assertSame(MultiAgentLiveCycleExecutorService::STATUS_BLOCKED, $r['status']);
        $this->assertSame(0, $r['lane_count']);
        $this->assertFalse($r['provider_invoked']);
        $this->assertContains('operator_or_architect_spec_required', $r['blockers']);
    }

    public function test_validation_failure_triggers_repair_plan_and_repair_lane(): void
    {
        $owner = $this->ownerRuntimeReal([
            'validation' => [
                'ran' => true,
                'passed' => false,
                'commands' => ['php artisan test tests/Unit/FooServiceTest.php'],
                'failing_tests' => ['FooServiceTest::testThing'],
            ],
        ]);

        $r = $this->executor()->execute([
            'execute' => true,
            'finding' => ['finding_id' => 'f1', 'kind' => 'test'],
            'executable_slice' => $this->slice(),
            'owner_runtime_result' => $owner,
            'session_id' => 'sess-repair',
            'cycle_id' => 'cyc-repair',
        ]);

        $this->assertSame(MultiAgentLiveCycleExecutorService::STATUS_REPAIR_REQUIRED, $r['status']);
        $this->assertSame(MultiAgentIntegrationJudgeService::STATUS_REPAIR_REQUIRED, $r['judge_decision']['status']);
        $this->assertNotNull($r['repair_decision']);
        $this->assertSame(MultiAgentRepairPlannerService::DECISION_REPAIR, $r['repair_decision']['repair_decision']);
        $this->assertTrue($r['repair_decision']['repair_allowed']);

        $roles = array_map(static fn (array $s): string => $s['role'], $r['lane_sessions']);
        $this->assertContains('repair_agent', $roles);
        $this->assertFalse($r['merge_eligible']);
    }

    public function test_reviewer_and_judge_have_no_write_authority_only_implementer_writes(): void
    {
        $r = $this->executor()->execute([
            'execute' => true,
            'finding' => ['finding_id' => 'f1', 'kind' => 'test'],
            'executable_slice' => $this->slice(),
            'owner_runtime_result' => $this->ownerRuntimeReal(),
            'session_id' => 'sess-auth',
            'cycle_id' => 'cyc-auth',
        ]);

        $byRole = [];
        foreach ($r['lane_sessions'] as $session) {
            $byRole[$session['role']] = $session;
        }

        $this->assertSame('worktree_write', $byRole['implementer']['write_authority']);
        $this->assertSame('read_only', $byRole['context_scout']['write_authority']);
        $this->assertSame('read_only', $byRole['reviewer']['write_authority']);
        $this->assertSame('read_only_no_merge', $byRole['judge']['write_authority']);
        $this->assertSame('spec_only', $byRole['architect']['write_authority']);

        // The only write lane reported is the implementer's.
        $this->assertSame([$byRole['implementer']['lane_id']], $r['write_lanes']);
        $this->assertSame($byRole['implementer']['lane_id'], $r['write_lane']);
        // Only the implementer lane is a real provider invocation.
        $this->assertTrue($byRole['implementer']['provider_invoked']);
        $this->assertFalse($byRole['reviewer']['provider_invoked']);
        $this->assertFalse($byRole['judge']['provider_invoked']);
    }

    public function test_test_mode_never_certifies_production_even_on_happy_path(): void
    {
        $r = $this->executor()->execute([
            'execute' => true,
            'finding' => ['finding_id' => 'f1', 'kind' => 'test'],
            'executable_slice' => $this->slice(),
            'owner_runtime_result' => $this->ownerRuntimeReal(),
            'session_id' => 'sess-cert',
            'cycle_id' => 'cyc-cert',
            // use_real_services omitted -> test_mode.
        ]);

        $this->assertFalse($r['production_certified']);
        $this->assertSame('test_mode', $r['certification']['certification_mode']);
        $this->assertContains($r['certification']['status'], ['partial', 'blocked']);
    }

    public function test_plan_only_mode_executes_nothing(): void
    {
        $r = $this->executor()->execute([
            'execute' => false,
            'finding' => ['finding_id' => 'f1', 'kind' => 'test'],
            'executable_slice' => $this->slice(),
            'session_id' => 'sess-plan',
            'cycle_id' => 'cyc-plan',
        ]);

        $this->assertSame(MultiAgentLiveCycleExecutorService::STATUS_PLANNED, $r['status']);
        $this->assertFalse($r['provider_invoked']);
        $this->assertFalse($r['merge_eligible']);
        $this->assertFalse($r['production_certified']);
    }

    public function test_each_lane_records_a_distinct_durable_session(): void
    {
        $this->executor()->execute([
            'execute' => true,
            'finding' => ['finding_id' => 'f1', 'kind' => 'test'],
            'executable_slice' => $this->slice(),
            'owner_runtime_result' => $this->ownerRuntimeReal(),
            'session_id' => 'sess-persist',
            'cycle_id' => 'cyc-persist',
        ]);

        $store = $this->sessionStore();
        $records = $store->replay('sess-persist');
        $this->assertCount(5, $records);
        $hashes = array_map(static fn (array $r): string => $r['session_hash'], $records);
        $this->assertCount(5, array_unique($hashes), 'each lane must be a distinct durable session');

        $latest = $store->latestByCycleId('cyc-persist');
        $this->assertNotNull($latest);
        $this->assertSame('judge', $latest['lane']);
    }

    public function test_receipt_is_deterministic_for_identical_input(): void
    {
        $input = [
            'execute' => true,
            'finding' => ['finding_id' => 'f1', 'kind' => 'test'],
            'executable_slice' => $this->slice(),
            'owner_runtime_result' => $this->ownerRuntimeReal(),
            'session_id' => 'sess-det',
            'cycle_id' => 'cyc-det',
        ];

        $a = $this->executor()->execute($input);
        $b = $this->executor()->execute($input);

        $this->assertSame($a['cycle_receipt_hash'], $b['cycle_receipt_hash']);
        $this->assertSame($a['lane_plan_hash'], $b['lane_plan_hash']);
    }
}
